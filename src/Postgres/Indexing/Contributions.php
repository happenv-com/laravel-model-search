<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Indexing;

use Happenv\ModelSearch\Compilation\BindingGuard;
use Happenv\ModelSearch\Compilation\ColumnTypes;
use Happenv\ModelSearch\Compilation\CompiledPath;
use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\CompiledField;
use Happenv\ModelSearch\Definition\FieldType;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Normalization\KeyNormalizer;
use Happenv\ModelSearch\Normalization\NameNormalizer;
use Happenv\ModelSearch\Normalization\NameWords;
use Happenv\ModelSearch\Normalization\Normalizer;
use Illuminate\Database\Connection;

/**
 * Computes, from the CURRENT state of the source tables, what the index should hold for some roots:
 * the entry (stage, names, sort moment, partition), one key row per normalized key value, and one
 * `name_word` row per word of the record's names. Nothing is hydrated; every field is one SQL query
 * over all the roots of the batch.
 *
 * A root that does not exist, or is soft deleted, has no contribution, so the writer removes it.
 * A normalizer may return an empty string: such a value is dropped, never indexed.
 */
final class Contributions
{
    /**
     * @param  list<string>  $rootIds
     * @return array<string, RootContribution> Keyed by root id; only roots that exist.
     */
    public function for(CompiledDefinition $definition, array $rootIds, Connection $db): array
    {
        if ($rootIds === []) {
            return [];
        }

        $roots = $this->roots($definition, $rootIds, $db);

        if ($roots === []) {
            return [];
        }

        $existing = array_map(strval(...), array_keys($roots));
        $stages = $this->stages($definition, $existing, $db);

        /** @var array<string, list<array{kind: string, key: string, value: string}>> $keys */
        $keys = [];
        /** @var array<string, list<array{normalized: string, source: string}>> $names */
        $names = [];
        /** @var array<string, list<array{normalized: string, source: string}>> $wordSources */
        $wordSources = [];

        foreach ($definition->fields as $field) {
            if ($field->compiledPath === null || ! in_array($field->type, [FieldType::Key, FieldType::Name], true)) {
                continue;
            }

            $normalizer = $field->normalizer ?? ($field->type === FieldType::Name ? new NameNormalizer : new KeyNormalizer);

            foreach ($this->values($field, $existing, $db) as $rootId => $values) {
                foreach ($values as $value) {
                    $normalized = $normalizer->normalize($value);

                    if ($normalized === '') {
                        continue;
                    }

                    if ($field->type === FieldType::Key) {
                        $keys[$rootId][] = ['kind' => $field->kind, 'key' => $normalized, 'value' => $value];

                        continue;
                    }

                    $names[$rootId][] = ['normalized' => $normalized, 'source' => $value];

                    if (in_array(Mode::Prefix, $field->modes, true)) {
                        $wordSources[$rootId][] = ['normalized' => $normalized, 'source' => $value];
                    }
                }
            }
        }

        $cap = max(0, (int) config('model-search.name_words.max_per_record', 12));
        $contributions = [];

        foreach ($roots as $rootId => $root) {
            $rootId = (string) $rootId;
            $stage = $stages[$rootId] ?? CompiledDefinition::ALL_STAGE;
            $rows = [];

            foreach ($keys[$rootId] ?? [] as $key) {
                $rows[$key['kind']."\0".$key['key']] ??= $key;
            }

            $nameList = [];

            foreach ($names[$rootId] ?? [] as $name) {
                $nameList[$name['normalized']] = true;
            }

            foreach ($this->words($wordSources[$rootId] ?? [], $cap) as $word => $source) {
                $rows[SearchField::RESERVED_KIND."\0".$word] ??= ['kind' => SearchField::RESERVED_KIND, 'key' => (string) $word, 'value' => $source];
            }

            $contributions[$rootId] = new RootContribution(
                rootId: $rootId,
                stage: $stage,
                names: implode(' ', array_map(strval(...), array_keys($nameList))),
                sortAt: $root['sort_at'],
                partition: $root['partition'],
                keys: array_values(array_map(
                    static fn (array $row): array => [...$row, 'stage' => $stage],
                    $rows,
                )),
            );
        }

        return $contributions;
    }

    /**
     * The words of all the names of one record, in order of appearance, at most `$cap` of them.
     *
     * @param  list<array{normalized: string, source: string}>  $names
     * @return array<string, string> Word => the source name it came from.
     */
    private function words(array $names, int $cap): array
    {
        $words = [];

        foreach ($names as $name) {
            foreach (NameWords::of($name['normalized']) as $word) {
                if ($word === '' || isset($words[$word])) {
                    continue;
                }

                if (count($words) >= $cap) {
                    return $words;
                }

                $words[$word] = $name['source'];
            }
        }

        return $words;
    }

    /**
     * @param  list<string>  $rootIds
     * @return array<string, array{sort_at: string|null, partition: string|null}>
     */
    private function roots(CompiledDefinition $definition, array $rootIds, Connection $db): array
    {
        $model = new ($definition->modelClass);
        $key = Sql::ident($model->getKeyName());
        $type = $definition->keySqlType ?? new ColumnTypes()->of($model, $model->getTable(), $model->getKeyName());
        $partition = $definition->partition ?? config('model-search.partition_column');
        $soft = Sql::softDeleteColumn($model);

        $sql = sprintf(
            'SELECT "r".%1$s::text AS "root_id", %2$s AS "sort_at", %3$s AS "partition" FROM %4$s AS "r" WHERE "r".%1$s = ANY(?::%5$s[])%6$s',
            $key,
            $definition->sortAt === null ? 'NULL::text' : sprintf('"r".%s::timestamptz::text', Sql::ident($definition->sortAt)),
            is_string($partition) ? sprintf('"r".%s::text', Sql::ident($partition)) : 'NULL::text',
            Sql::table($model),
            $type,
            $soft === null ? '' : sprintf(' AND "r".%s IS NULL', Sql::ident($soft)),
        );

        $roots = [];

        foreach ($db->select($sql, [Sql::arrayLiteral($rootIds)]) as $row) {
            $roots[(string) $row->root_id] = [
                'sort_at' => $row->sort_at === null ? null : (string) $row->sort_at,
                'partition' => $row->partition === null ? null : (string) $row->partition,
            ];
        }

        return $roots;
    }

    /**
     * @param  list<string>  $rootIds
     * @return array<string, int>
     */
    private function stages(CompiledDefinition $definition, array $rootIds, Connection $db): array
    {
        $query = $definition->stageSql($rootIds);
        $stages = [];

        foreach ($db->select($query['sql'], BindingGuard::canonical($query['bindings'], 'A stage')) as $row) {
            $stages[(string) $row->root_id] = (int) $row->stage;
        }

        return $stages;
    }

    /**
     * The raw values of one field per root, in a stable order.
     *
     * @param  list<string>  $rootIds
     * @return array<string, list<string>>
     */
    private function values(CompiledField $field, array $rootIds, Connection $db): array
    {
        $path = $field->compiledPath;

        if (! $path instanceof CompiledPath) {
            return [];
        }

        $query = $path->contributionSql($rootIds);
        $values = [];

        foreach ($db->select($query['sql'], BindingGuard::canonical($query['bindings'], 'A field path')) as $row) {
            $values[(string) $row->root_id][] = (string) $row->value;
        }

        foreach ($values as &$list) {
            sort($list, SORT_STRING);
        }

        return $values;
    }
}
