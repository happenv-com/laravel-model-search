<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Compilation\WatchedTable;
use Illuminate\Database\Eloquent\Model;

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
    ) {}

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

        $root = new WatchedTable($model->getTable(), [], 'self', $this->morphAlias, $model->getKeyName())->withColumns($rootColumns);
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
     * @param  list<string|int>  $rootIds
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function stageSql(array $rootIds): array
    {
        $model = new ($this->modelClass);
        $key = Sql::ident($model->getKeyName());
        $literal = Sql::arrayLiteral($rootIds);
        $bindings = [];
        $cases = '';

        foreach ($this->stages as $position => $stage) {
            $constraint = $stage->constraint;

            if (! $constraint instanceof \Closure) {
                continue;
            }

            $inner = $model->newQueryWithoutScopes();
            $inner->select($model->getKeyName());
            $inner->whereIn($model->getQualifiedKeyName(), $rootIds);
            $inner->where(static function ($query) use ($constraint): void {
                $constraint($query);
            });

            $base = $inner->getQuery();
            $cases .= sprintf(' WHEN "r".%s IN (%s) THEN %d', $key, $base->toSql(), $position);
            array_push($bindings, ...$base->getBindings());
        }

        $stage = $cases === '' ? sprintf('%d', self::ALL_STAGE) : sprintf('CASE%s ELSE %d END', $cases, self::ALL_STAGE);

        return [
            'sql' => sprintf(
                'SELECT "r".%1$s::text AS "root_id", (%2$s)::smallint AS "stage" FROM %3$s AS "r" WHERE %4$s',
                $key,
                $stage,
                Sql::table($model),
                Sql::anyOf('"r".'.$key, $this->keyType),
            ),
            'bindings' => [...$bindings, $literal],
        ];
    }
}
