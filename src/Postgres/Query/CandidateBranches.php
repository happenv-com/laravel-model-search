<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

use Closure;
use Happenv\ModelSearch\Compilation\CompiledPath;
use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\CompiledField;
use Happenv\ModelSearch\Definition\FieldType;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchStage;
use Happenv\ModelSearch\Drivers\DatabaseDriver;
use Happenv\ModelSearch\Drivers\Fragment;
use Happenv\ModelSearch\Drivers\Hit;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Exceptions\UnknownDefinition;
use Happenv\ModelSearch\Normalization\LikeEscaper;
use Happenv\ModelSearch\Normalization\NameWords;
use Happenv\ModelSearch\Normalization\Normalizer;
use Happenv\ModelSearch\Normalization\PhraseShape;
use Happenv\ModelSearch\Normalization\QueryVariants;
use Happenv\ModelSearch\Postgres\Schema\IndexPlan;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Query\HostQuery;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The candidate branches of ONE type in round one of a palette: every way the phrase can match
 * a record of the type, each read from the index in the order of the index, with a limit.
 *
 * Every branch is a parenthesised `SELECT … ORDER BY <index expression> LIMIT pool + 1` over the
 * keys table, with the same columns, so the branches of all types join into one `UNION ALL`:
 *
 *     ti, subject_id, level, kind, value, via, sim, s0, bk, src, br
 *
 * `ti` is the type's position in the palette, `level` the rank of the mode (a hit through a parent
 * adds {@see Hit::VIA_OFFSET}), `s0` whether the key row belongs to the first stage, `bk` the kind
 * of branch (see the constants), `src` whether the row came from the source table, `br` the id of
 * the branch (unique in the palette), which the statement uses to tell a branch that filled its
 * pool from one that did not.
 *
 * - `Exact`: every stage;
 * - `Prefix` and `Suffix` of keys: `kind <> 'name_word'`; a type with stages gets two branches, the
 *   first stage and all stages;
 * - `Prefix` of names: the name words, `kind = 'name_word'`, with the same stage variants. A phrase
 *   of several words reads the candidates of its longest word and asks, for the other words,
 *   whether the same record has a word that starts with them. A phrase shaped like an identifier
 *   skips the names;
 * - `exactOnSource`: the host query joined with the variants of the phrase, so a record the index
 *   has not seen yet is found by its exact number;
 * - `via`: candidates of the parent (`Exact`, `Prefix`, `Suffix` of the kinds the field follows),
 *   kept only for parents inside the scope of the PARENT, before the limit of
 *   {@see DatabaseDriver::VIA_PARENT_LIMIT}, then the children by foreign key. Without a scope for
 *   the parent the branch stays closed.
 *
 * The predicates of the partial indexes (`kind <> 'name_word'`, `kind = 'name_word'`,
 * `stage < 32767`) come from {@see IndexPlan} and are written into the statement text, never bound:
 * the planner matches a partial index only by a predicate it can read.
 *
 * @internal
 */
final class CandidateBranches
{
    /** The branch ids of type `n` start at `n * TYPE_STRIDE`. */
    public const int TYPE_STRIDE = 1000;

    /** A branch that is neither stage variant: `Exact`, the source, `via`, or a type without stages. */
    public const int PLAIN = 0;

    /** The variant of a type with stages that reads only the key rows of the first stage. */
    public const int STAGE_ZERO = 1;

    /** The variant of a type with stages that reads the key rows of every stage. */
    public const int ALL_STAGES = 2;

    private const int MIN_PREFIX = 3;

    private const int MIN_SUFFIX = 4;

    private int $counter = 0;

    private readonly Model $model;

    private readonly string $keys;

    private readonly bool $staged;

    private function __construct(
        private readonly CompiledDefinition $definition,
        private readonly SearchQuery $query,
        private readonly int $pool,
        private readonly int $typeIndex,
    ) {
        $this->model = new ($definition->modelClass);
        $this->keys = TableNames::forConnection(Sql::connectionName($this->model))->quoted(TableNames::forConnection(Sql::connectionName($this->model))->keys());
        $this->staged = $query->stagesEnabled() && $definition->constrainedStage() instanceof SearchStage;
    }

    /**
     * @return list<Fragment> Each already wrapped in parentheses.
     */
    public static function for(CompiledDefinition $definition, SearchQuery $query, int $pool, int $typeIndex = 0): array
    {
        return new self($definition, $query, $pool, $typeIndex)->build();
    }

    /**
     * Whether a type has a stage-zero variant in this query.
     */
    public static function isStaged(CompiledDefinition $definition, SearchQuery $query): bool
    {
        return $query->stagesEnabled() && $definition->constrainedStage() instanceof SearchStage;
    }

    /**
     * @return list<Fragment>
     */
    private function build(): array
    {
        $branches = [];

        foreach ($this->keyPlan($this->definition, $this->query->onlyKinds()) as $item) {
            $stageVariants = $item['mode'] === Mode::Exact || ! $this->staged ? [self::PLAIN] : [self::STAGE_ZERO, self::ALL_STAGES];

            foreach ($stageVariants as $variant) {
                $branches[] = $this->keyBranch($item['mode'], $item['variant'], $item['kinds'], $variant);
            }
        }

        foreach ($this->nameSets() as $set) {
            foreach ($this->staged ? [self::STAGE_ZERO, self::ALL_STAGES] : [self::PLAIN] as $variant) {
                $branches[] = $this->wordBranch($set['main'], $set['others'], $variant);
            }
        }

        foreach ($this->definition->fields as $field) {
            if ($field->exactOnSource && $this->chosen($field) && $this->allows(Mode::Exact) && QueryVariants::keysFor($this->query->phraseText(), $this->query->viewerLocale()) !== []) {
                $branch = $this->sourceBranch($field);

                if ($branch instanceof Fragment) {
                    $branches[] = $branch;
                }
            }

            if ($field->type === FieldType::Via && $this->chosen($field)) {
                $branch = $this->viaBranch($field);

                if ($branch instanceof Fragment) {
                    $branches[] = $branch;
                }
            }
        }

        return $branches;
    }

    /**
     * What to read from the keys of a definition: a mode, the variant of the phrase to look for, and
     * the kinds that variant applies to (null: every key kind of the definition).
     *
     * A field with a normalizer of its own is looked up by what the normalizer makes of the raw
     * phrase; the others by the neutral and locale forms of the phrase.
     *
     * @param  list<string>  $kinds  Only these kinds; empty means every kind.
     * @return list<array{mode: Mode, variant: string, kinds: list<string>|null}>
     */
    private function keyPlan(CompiledDefinition $definition, array $kinds): array
    {
        $phrase = $this->query->phraseText();
        $default = QueryVariants::keysFor($phrase, $this->query->viewerLocale());
        $all = [];
        $groups = [];

        foreach ($definition->fields as $field) {
            if ($field->type !== FieldType::Key || ! $field->compiledPath instanceof CompiledPath) {
                continue;
            }

            $all[$field->kind] = true;

            if ($kinds !== [] && ! in_array($field->kind, $kinds, true)) {
                continue;
            }

            if ($field->normalizer instanceof Normalizer) {
                $custom = $field->normalizer->normalize($phrase);
                $variants = $custom === '' ? [] : [$custom];
            } else {
                $variants = $default;
            }

            foreach ([Mode::Exact, Mode::Prefix, Mode::Suffix] as $mode) {
                if (! in_array($mode, $field->modes, true) || ! $this->allows($mode)) {
                    continue;
                }

                foreach ($variants as $variant) {
                    if (mb_strlen($variant) >= $this->minLength($mode)) {
                        $groups[$mode->value."\0".$variant]['mode'] = $mode;
                        $groups[$mode->value."\0".$variant]['variant'] = $variant;
                        $groups[$mode->value."\0".$variant]['kinds'][$field->kind] = true;
                    }
                }
            }
        }

        $plan = [];

        foreach ($groups as $group) {
            $names = array_keys($group['kinds']);
            $plan[] = [
                'mode' => $group['mode'],
                'variant' => $group['variant'],
                'kinds' => count($names) === count($all) ? null : array_map(strval(...), $names),
            ];
        }

        return $plan;
    }

    /**
     * One read of the generic keys.
     *
     * @param  list<string>|null  $kinds
     */
    private function keyBranch(Mode $mode, string $variant, ?array $kinds, int $stageVariant): Fragment
    {
        [$condition, $order, $binding] = $this->condition($mode, $variant);
        $where = [sprintf('k.subject_type = ? AND k.%s', IndexPlan::GENERIC_KEYS)];
        $bindings = [$this->definition->morphAlias];

        $this->narrow($where, $bindings, $stageVariant, $kinds, $this->query->partitionFilter());
        $where[] = $condition;
        $bindings[] = $binding;

        return new Fragment(
            sprintf(
                '(SELECT %d AS ti, k.subject_id, %d AS level, k.kind, k.value, CAST(NULL AS text) AS via, CAST(0 AS real) AS sim, (k.stage < %d) AS s0, %d AS bk, false AS src, %d AS br, false AS ptr FROM %s k WHERE %s%s LIMIT %d)',
                $this->typeIndex,
                $mode->rank(),
                CompiledDefinition::ALL_STAGE,
                $stageVariant,
                $this->nextBranch(),
                $this->keys,
                implode(' AND ', $where),
                $order,
                $this->pool + 1,
            ),
            $bindings,
        );
    }

    /**
     * The name words: the longest word of the phrase read as a prefix, in index order; every other
     * word must start a word of the same record. A word equal to the longest word ranks above a
     * longer word that only starts with it.
     *
     * @param  list<string>  $others
     */
    private function wordBranch(string $main, array $others, int $stageVariant): Fragment
    {
        $select = [$main];
        $laterals = [];
        $lateralBindings = [];
        $bindings = [$this->definition->morphAlias];
        $where = [sprintf('k.subject_type = ? AND k.%s', IndexPlan::NAME_WORDS)];

        // Each other word is a one-row lookup per candidate, so the scan of the main word still ends at the limit.
        foreach ($others as $position => $other) {
            $laterals[] = sprintf(
                " JOIN LATERAL (SELECT 1 FROM %s w WHERE w.subject_type = k.subject_type AND w.subject_id = k.subject_id AND w.%s AND w.key LIKE ? ESCAPE '\\' LIMIT 1) o%d ON true",
                $this->keys,
                IndexPlan::NAME_WORDS,
                $position,
            );
            $lateralBindings[] = LikeEscaper::escape($other).'%';
        }

        $this->narrow($where, $bindings, $stageVariant, null, $this->query->partitionFilter());
        $where[] = "k.key LIKE ? ESCAPE '\\'";
        $bindings[] = LikeEscaper::escape($main).'%';

        return new Fragment(
            sprintf(
                '(SELECT %d AS ti, k.subject_id, %d AS level, k.kind, k.value, CAST(NULL AS text) AS via, CAST((k.key = ?) AS integer) AS sim, (k.stage < %d) AS s0, %d AS bk, false AS src, %d AS br, false AS ptr FROM %s k%s WHERE %s ORDER BY k.key USING ~<~ LIMIT %d)',
                $this->typeIndex,
                Mode::Prefix->rank(),
                CompiledDefinition::ALL_STAGE,
                $stageVariant,
                $this->nextBranch(),
                $this->keys,
                implode('', $laterals),
                implode(' AND ', $where),
                $this->pool + 1,
            ),
            [...$select, ...$lateralBindings, ...$bindings],
        );
    }

    /**
     * The host query joined with the variants of the phrase on the field's own column.
     */
    private function sourceBranch(CompiledField $field): ?Fragment
    {
        $phrase = $this->query->phraseText();
        $trimmed = trim($phrase);
        $variants = array_values(array_unique(array_filter(
            [$phrase, $trimmed, mb_strtoupper($trimmed), mb_strtolower($trimmed)],
            static fn (string $variant): bool => $variant !== '',
        )));

        if ($variants === []) {
            return null;
        }

        $host = $this->query->scope()->equalingColumn($field->path, $variants, $this->query->partitionFilter());
        $stage = $this->staged ? $this->definition->constrainedStage() : null;
        $inStage = $stage instanceof SearchStage
            ? Fragment::from($this->definition->stageExists($stage, 's.'.HostQuery::KEY_ALIAS))
            : new Fragment('false');

        return new Fragment(
            sprintf(
                '(SELECT %1$d AS ti, s.%2$s::text AS subject_id, 0 AS level, CAST(? AS text) AS kind, s.source_value::text AS value, CAST(NULL AS text) AS via, CAST(0 AS real) AS sim, %3$s AS s0, %4$d AS bk, true AS src, %5$d AS br, false AS ptr FROM (%6$s) AS s LIMIT %7$d)',
                $this->typeIndex,
                Sql::ident(HostQuery::KEY_ALIAS),
                $inStage->sql,
                self::PLAIN,
                $this->nextBranch(),
                $host['sql'],
                $this->pool + 1,
            ),
            [$field->kind, ...$inStage->bindings, ...$host['bindings']],
        );
    }

    /**
     * Records found through a parent: the parent's candidates inside the parent's scope, then the
     * records that belong to them. Closed (null) without a scope for the parent or when the relation
     * is not a BelongsTo.
     */
    private function viaBranch(CompiledField $field): ?Fragment
    {
        $relation = method_exists($this->model, $field->path) ? $this->model->{$field->path}() : null;

        if (! $relation instanceof BelongsTo) {
            return null;
        }

        $parentClass = $relation->getRelated()::class;
        $parentScope = $this->query->parentScope($parentClass);

        if (! $parentScope instanceof HostQuery) {
            return null;
        }

        try {
            $parentDefinition = resolve(ModelSearchRegistry::class)->for($parentClass);
        } catch (UnknownDefinition) {
            throw new InvalidSearchDefinition(sprintf(
                'The via field "%s" of %s follows %s, whose scope was given, but %s has no search definition.',
                $field->path,
                $this->definition->modelClass,
                $parentClass,
                $parentClass,
            ));
        }

        $candidates = [];

        foreach ($this->keyPlan($parentDefinition, $field->via) as $item) {
            $candidates[] = $this->parentCandidates($parentDefinition, $parentScope, $item);
        }

        if ($candidates === []) {
            return null;
        }

        $parent = new ($parentDefinition->modelClass);
        $parentKeyType = $parentDefinition->resolvedKeySqlType();
        $revealing = array_values(array_map(
            static fn (CompiledField $parentField): string => $parentField->kind,
            array_filter($parentDefinition->fields, static fn (CompiledField $parentField): bool => $parentField->reveal instanceof Closure),
        ));
        $ownerKey = $relation->getOwnerKeyName();
        $viaOwnerKey = $ownerKey !== $parent->getKeyName();
        $childKey = $viaOwnerKey ? 'pt.'.Sql::ident($ownerKey) : sprintf('pc.subject_id::%s', $parentKeyType);
        $union = Fragment::join($candidates, ' UNION ALL ');
        $union = new Fragment(
            sprintf(
                'SELECT q.subject_id, q.level, q.kind, q.value, q.over FROM (SELECT d.*, row_number() OVER (ORDER BY d.level, d.subject_id) AS rn, count(*) OVER () > %1$d AS over FROM (SELECT DISTINCT ON (p.subject_id) p.subject_id, p.level, p.kind, p.value FROM (%2$s) AS p ORDER BY p.subject_id, p.level, p.kind) AS d) AS q WHERE q.rn <= %1$d',
                DatabaseDriver::VIA_PARENT_LIMIT,
                $union->sql,
            ),
            $union->bindings,
        );
        $entries = TableNames::forConnection(Sql::connectionName($this->model))->quoted(TableNames::forConnection(Sql::connectionName($this->model))->entries());
        $stageBindings = $this->staged ? [$this->definition->morphAlias] : [];
        $partition = $this->query->partitionFilter();

        return new Fragment(
            sprintf(
                '(SELECT %1$d AS ti, ch.%2$s::text AS subject_id, (%3$d + pc.level) AS level, CAST(? AS text) AS kind, CASE WHEN pc.kind = ANY(?::text[]) THEN NULL ELSE pc.value END AS value, CAST(? AS text) AS via, CAST(0 AS real) AS sim, %4$s AS s0, %5$d AS bk, false AS src, %6$d AS br, pc.over AS ptr FROM (%7$s) AS pc%8$s JOIN %9$s AS ch ON ch.%10$s = %11$s%14$s%12$s LIMIT %13$d)',
                $this->typeIndex,
                Sql::ident($this->model->getKeyName()),
                Hit::VIA_OFFSET,
                $this->staged ? sprintf('COALESCE(ce.stage < %d, false)', CompiledDefinition::ALL_STAGE) : 'false',
                self::PLAIN,
                $this->nextBranch(),
                $union->sql,
                $viaOwnerKey ? sprintf(' JOIN %s AS pt ON pt.%s = pc.subject_id::%s', Sql::table($parent), Sql::ident($parent->getKeyName()), $parentKeyType) : '',
                Sql::table($this->model),
                Sql::ident($relation->getForeignKeyName()),
                $childKey,
                $this->staged ? sprintf(' LEFT JOIN %s AS ce ON ce.subject_type = ? AND ce.subject_id = ch.%s::text', $entries, Sql::ident($this->model->getKeyName())) : '',
                $this->pool + 1,
                $partition === null ? '' : sprintf(' AND ch.%s = ?', Sql::ident($partition['column'])),
            ),
            [$field->kind, Sql::arrayLiteral($revealing), $field->kind, ...$union->bindings, ...($partition === null ? [] : [$partition['value']]), ...$stageBindings],
        );
    }

    /**
     * The candidates of the parent for one mode and variant, kept only for parents inside the parent's
     * scope, before the limit of parents.
     *
     * @param  array{mode: Mode, variant: string, kinds: list<string>|null}  $item
     */
    private function parentCandidates(CompiledDefinition $parentDefinition, HostQuery $scope, array $item): Fragment
    {
        $host = $scope->sql();
        [$condition, $order, $binding] = $this->condition($item['mode'], $item['variant']);
        $where = [sprintf('k.subject_type = ? AND k.%s', IndexPlan::GENERIC_KEYS)];
        $bindings = [$parentDefinition->morphAlias];

        $this->narrow($where, $bindings, self::PLAIN, $item['kinds'], $this->parentPartition($parentDefinition));
        $where[] = $condition;
        $bindings[] = $binding;

        return new Fragment(
            sprintf(
                '(SELECT k.subject_id, %1$d AS level, k.kind, k.value FROM %2$s k JOIN LATERAL (SELECT 1 FROM (%3$s) h WHERE h.%4$s = k.subject_id::%5$s LIMIT 1) auth ON true WHERE %6$s%7$s LIMIT %8$d)',
                $item['mode']->rank(),
                $this->keys,
                $host['sql'],
                Sql::ident(HostQuery::KEY_ALIAS),
                $parentDefinition->resolvedKeySqlType(),
                implode(' AND ', $where),
                $order,
                DatabaseDriver::VIA_PARENT_LIMIT + 1,
            ),
            [...$host['bindings'], ...$bindings],
        );
    }

    /**
     * The partition the parent's keys are narrowed by: the parent definition's own column (none when
     * the parent is not partitioned) with the value the query was given.
     *
     * @return array{column: string, value: string|int}|null
     */
    private function parentPartition(CompiledDefinition $parentDefinition): ?array
    {
        $column = $parentDefinition->partition ?? config('model-search.partition_column');
        $value = $this->query->partitionValue();

        return is_string($column) && $column !== '' && $value !== null ? ['column' => $column, 'value' => $value] : null;
    }

    /**
     * The conditions every read of the keys shares: the partition, the stage variant and the kinds.
     * They are appended after the type and the index predicate, in the order of their placeholders.
     *
     * @param  list<string>  $where
     * @param  list<mixed>  $bindings
     * @param  list<string>|null  $kinds
     * @param  array{column: string, value: string|int}|null  $partition
     */
    private function narrow(array &$where, array &$bindings, int $stageVariant, ?array $kinds, ?array $partition): void
    {
        if ($partition !== null) {
            $where[] = 'k."partition" = ?';
            $bindings[] = (string) $partition['value'];
        }

        if ($stageVariant === self::STAGE_ZERO) {
            $where[] = 'k.'.IndexPlan::STAGE_ZERO;
        }

        if ($kinds !== null) {
            $where[] = 'k.kind = ANY(?::text[])';
            $bindings[] = Sql::arrayLiteral($kinds);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string} The condition, the ordering that keeps the index scan, the pattern.
     */
    private function condition(Mode $mode, string $variant): array
    {
        return match ($mode) {
            Mode::Exact => ['k.key = ?', '', $variant],
            Mode::Prefix => ["k.key LIKE ? ESCAPE '\\'", ' ORDER BY k.key USING ~<~', LikeEscaper::escape($variant).'%'],
            Mode::Suffix => ["reverse(k.key) LIKE ? ESCAPE '\\'", ' ORDER BY reverse(k.key) USING ~<~', LikeEscaper::escape($this->reversed($variant)).'%'],
            default => throw new InvalidSearchDefinition(sprintf('The palette does not read the mode "%s" from the index.', $mode->value)),
        };
    }

    /**
     * The word sets the phrase reads as name words: the longest word of each (the last of equals) is
     * read from the index, the rest must start words of the same record.
     *
     * @return list<array{main: string, others: list<string>}>
     */
    private function nameSets(): array
    {
        $phrase = $this->query->phraseText();

        if (PhraseShape::isIdentifierLike($phrase) || ! $this->allows(Mode::Prefix)) {
            return [];
        }

        $sets = [];

        foreach ($this->definition->fields as $field) {
            if ($field->type !== FieldType::Name || ! $field->compiledPath instanceof CompiledPath || ! in_array(Mode::Prefix, $field->modes, true) || ! $this->chosen($field)) {
                continue;
            }

            $forms = $field->normalizer instanceof Normalizer
                ? [$field->normalizer->normalize($phrase)]
                : QueryVariants::for($phrase, $this->query->viewerLocale());

            foreach ($forms as $form) {
                $words = NameWords::of($form);
                $longest = null;

                foreach ($words as $position => $word) {
                    if ($longest === null || mb_strlen($word) >= mb_strlen($words[$longest])) {
                        $longest = $position;
                    }
                }

                if ($longest === null || mb_strlen($words[$longest]) < self::MIN_PREFIX) {
                    continue;
                }

                $sets[implode(' ', $words)] = [
                    'main' => $words[$longest],
                    'others' => array_values(array_filter($words, static fn (int $position): bool => $position !== $longest, ARRAY_FILTER_USE_KEY)),
                ];
            }
        }

        return array_values($sets);
    }

    private function chosen(CompiledField $field): bool
    {
        $kinds = $this->query->onlyKinds();

        return $kinds === [] || in_array($field->kind, $kinds, true);
    }

    private function allows(Mode $mode): bool
    {
        $modes = $this->query->onlyModes();

        return $modes === [] || in_array($mode, $modes, true);
    }

    private function nextBranch(): int
    {
        return $this->typeIndex * self::TYPE_STRIDE + $this->counter++;
    }

    private function minLength(Mode $mode): int
    {
        return match ($mode) {
            Mode::Prefix => self::MIN_PREFIX,
            Mode::Suffix => self::MIN_SUFFIX,
            default => 1,
        };
    }

    private function reversed(string $value): string
    {
        return implode('', array_reverse(mb_str_split($value)));
    }
}
