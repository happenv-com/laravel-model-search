<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

use Happenv\ModelSearch\Compilation\ColumnTypes;
use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Compilation\WatchedTable;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;

final readonly class CompiledDefinition
{
    /** The stage of a record that falls in no constrained stage. */
    public const int ALL_STAGE = 32767;

    /**
     * @param  class-string<Model>  $modelClass
     * @param  'uuid'|'bigint'|'text'  $keyType
     * @param  list<CompiledField>  $fields
     * @param  list<SearchStage>  $stages  Always ends with {@see SearchStage::all()}.
     * @param  list<class-string>  $withScopes
     * @param  string|null  $keySqlType  Catalog type of the primary key column; looked up when null.
     */
    public function __construct(
        public string $modelClass,
        public string $morphAlias,
        public string $keyType,
        public array $fields,
        public array $stages,
        public ?string $sortAt,
        public ?string $partition,
        public bool $fallback = false,
        public int $version = 1,
        public array $withScopes = [],
        public ?string $keySqlType = null,
    ) {
        if ($keySqlType !== null && preg_match('/^[a-z][a-z0-9_]*$/', $keySqlType) !== 1) {
            throw new InvalidSearchDefinition(sprintf('"%s" is not a plain PostgreSQL type name.', $keySqlType));
        }
    }

    /**
     * Whether the definition narrows anything besides the final "all" stage.
     */
    public function hasStages(): bool
    {
        return array_any($this->stages, fn (SearchStage $stage): bool => ! $stage->isAll());
    }

    public function hasNames(): bool
    {
        return array_any($this->fields, fn (CompiledField $field): bool => $field->type === FieldType::Name);
    }

    /**
     * Every table whose changes can change this definition's index, with the columns that matter:
     * the root (field columns, stage columns, sort and partition columns, soft deletes, the foreign
     * keys of followed relations) and the tables of every path, merged per table and shape.
     *
     * @return list<WatchedTable>
     */
    public function watchedTables(): array
    {
        $model = new ($this->modelClass);
        $rootColumns = [];

        foreach ($this->fields as $field) {
            array_push($rootColumns, ...($field->compiledPath?->rootColumns() ?? []));
        }

        foreach ($this->stages as $stage) {
            array_push($rootColumns, ...$stage->watchedColumns());
        }

        $partition = $this->partition ?? config('model-search.partition_column');

        foreach ([$this->sortAt, is_string($partition) ? $partition : null, Sql::softDeleteColumn($model)] as $column) {
            if ($column !== null) {
                $rootColumns[] = $column;
            }
        }

        $root = new WatchedTable($model->getTable(), [], 'self', $this->morphAlias, $model->getKeyName(), null, null, Sql::connectionName($model), Sql::table($model))->withColumns($rootColumns);
        $merged = [$root->identity() => $root];

        foreach ($this->fields as $field) {
            foreach ($field->compiledPath?->tables() ?? [] as $table) {
                $key = $table->identity();
                $merged[$key] = isset($merged[$key]) ? $merged[$key]->withColumns([...$merged[$key]->columns, ...$table->columns]) : $table;
            }
        }

        return array_values($merged);
    }

    /**
     * The stage of each given root, computed in SQL without hydrating any model: one row per
     * existing root with its `root_id` (text) and `stage` (the index of the first constrained
     * stage it falls in, or {@see self::ALL_STAGE}).
     *
     * The ids travel as ONE array parameter and every stage is evaluated by a correlated subquery,
     * so the parameter count does not grow with the number of roots.
     *
     * @param  list<string|int>  $rootIds
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function stageSql(array $rootIds): array
    {
        $model = new ($this->modelClass);
        $key = Sql::ident($model->getKeyName());
        $type = $this->keySqlType ?? new ColumnTypes()->of($model, $model->getTable(), $model->getKeyName());
        $bindings = [];
        $cases = '';

        foreach ($this->stages as $position => $stage) {
            $constraint = $stage->constraint;

            if (! $constraint instanceof \Closure) {
                continue;
            }

            $inner = $model->newQueryWithoutScopes();
            $inner->selectRaw('1');
            $inner->whereColumn(new Expression('"u"."id"'), '=', $model->getQualifiedKeyName());
            $inner->where(static function ($query) use ($constraint): void {
                $constraint($query);
            });

            $base = $inner->getQuery();
            $cases .= sprintf(' WHEN EXISTS (%s) THEN %d', $base->toSql(), $position);
            array_push($bindings, ...$base->getBindings());
        }

        $stage = $cases === '' ? sprintf('%d', self::ALL_STAGE) : sprintf('CASE%s ELSE %d END', $cases, self::ALL_STAGE);

        return [
            'sql' => sprintf(
                'SELECT "u"."id"::text AS "root_id", (%1$s)::smallint AS "stage" FROM unnest(?::%2$s[]) AS "u"("id") JOIN %3$s AS "r" ON "r".%4$s = "u"."id"',
                $stage,
                $type,
                Sql::table($model),
                $key,
            ),
            'bindings' => [...$bindings, Sql::arrayLiteral($rootIds)],
        ];
    }
}
