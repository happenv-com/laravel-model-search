<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Query;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Exceptions\UnsupportedHostQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * The query a host application scopes a search with (what the viewer may see), as it is embedded
 * in a search statement.
 *
 * Only `from`, joins, conditions and global scopes are accepted (and a select of exactly `<table>.*`, which
 * joined hosts use to keep the model's own columns): wrapped in a join, a limit, an
 * ordering, a grouping, a union or a custom select would mean something else. The host builder is
 * never mutated; every use is a clone.
 *
 * @internal
 */
final readonly class HostQuery
{
    /** The alias of the primary key column in the embedded statement. */
    public const string KEY_ALIAS = 'search_key';

    /**
     * @param  Builder<Model>  $builder
     */
    private function __construct(private Builder $builder) {}

    /**
     * @param  Builder<Model>  $builder
     */
    public static function from(Builder $builder): self
    {
        self::guard($builder->clone()->applyScopes()->getQuery(), $builder->getModel());

        return new self($builder->clone());
    }

    public function model(): Model
    {
        return $this->builder->getModel();
    }

    /**
     * The statement selecting the primary key of every record in scope.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function sql(): array
    {
        $model = $this->builder->getModel();
        $builder = $this->builder->clone();
        $builder->select($model->getQualifiedKeyName().' as '.self::KEY_ALIAS);
        $statement = $builder->applyScopes()->getQuery();

        return ['sql' => $statement->toSql(), 'bindings' => $statement->getBindings()];
    }

    /**
     * The records in scope whose own column equals one of the values, as `search_key` and
     * `source_value`. The values join the query through `unnest(…)`, so a top-level `orWhere` of the
     * host cannot widen the result: the phrase is a condition of the join, never of the `WHERE`.
     *
     * @param  list<string>  $values
     * @param  array{column: string, value: string|int}|null  $partition  Kept as a condition of the join too.
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function equalingColumn(string $column, array $values, ?array $partition = null): array
    {
        $model = $this->builder->getModel();
        $query = $this->builder->clone()->applyScopes()->getQuery();
        $variants = $query->newQuery()->fromRaw('unnest(?::text[]) AS "source_variants"("variant")', [Sql::arrayLiteral($values)])->select('source_variants.variant');
        $qualified = $model->qualifyColumn($column);

        $query->select([$model->getQualifiedKeyName().' as '.self::KEY_ALIAS, $model->qualifyColumn($column).' as source_value']);
        $partitionColumn = $partition === null ? null : $model->qualifyColumn($partition['column']);
        $query->joinSub($variants, 'source_variants', static function (JoinClause $join) use ($qualified, $partition, $partitionColumn): void {
            $join->whereColumn($qualified, '=', 'source_variants.variant');

            if ($partition !== null && $partitionColumn !== null) {
                $join->where($partitionColumn, '=', $partition['value']);
            }
        });

        return ['sql' => $query->toSql(), 'bindings' => $query->getBindings()];
    }

    /**
     * The records with the given keys that are still in scope, in the given order, with the eager
     * loads and after-query callbacks of the host query. Runs on the host's own connection with
     * nothing but the key restriction added.
     *
     * @param  list<string|int>  $ids
     * @return Collection<int, Model>
     */
    public function hydrate(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $builder = $this->builder->clone();
        $model = $builder->getModel();
        $query = $builder->applyScopes()->getQuery();

        if (($query->joins ?? []) !== [] && ($query->columns === null || $query->columns === ['*'])) {
            // A joined host would otherwise return the columns of every joined table, last one winning.
            $builder->select($model->getTable().'.*');
        }

        $models = $builder->whereKey($ids)->get()->keyBy(static fn (Model $model): string => (string) $model->getKey());
        $ordered = new Collection;

        foreach ($ids as $id) {
            $model = $models->get((string) $id);

            if ($model instanceof Model) {
                $ordered->push($model);
            }
        }

        return $ordered;
    }

    private static function guard(QueryBuilder $query, Model $model): void
    {
        $offending = match (true) {
            $query->limit !== null => 'limit()',
            $query->offset !== null => 'offset()',
            $query->orders !== null && $query->orders !== [] => 'an ordering',
            $query->groups !== null && $query->groups !== [] => 'groupBy()',
            $query->havings !== null && $query->havings !== [] => 'having()',
            $query->unions !== null && $query->unions !== [] => 'union()',
            $query->aggregate !== null => 'an aggregate',
            $query->lock !== null => 'a lock',
            $query->columns !== null && $query->columns !== ['*'] && $query->columns !== [$model->getTable().'.*'] => 'a custom select',
            default => null,
        };

        if ($offending !== null) {
            throw new UnsupportedHostQuery(sprintf(
                'The host query uses %s. A search scope is embedded in the search statement, where that would change its meaning; keep only from, joins, conditions and global scopes.',
                $offending,
            ));
        }
    }
}
