<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

use Happenv\ModelSearch\Exceptions\InvalidStageConstraint;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Applies a stage constraint to a fresh query of the model and checks what it touches: only the
 * model's own table, only columns declared through {@see SearchStage::watching()}. A stage that
 * quietly depends on something else is an index that quietly goes stale.
 *
 * Time-relative values are refused when they are date objects. A time-relative constraint passed as a
 * plain string (for example `->toDateString()`) cannot be detected and is equally wrong.
 *
 * @internal
 */
final class StageConstraintInspector
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function inspect(string $modelClass, SearchStage $stage): void
    {
        $constraint = $stage->constraint;

        if (! $constraint instanceof \Closure) {
            return;
        }

        $model = new $modelClass;
        $builder = $model->newQueryWithoutScopes();
        $result = $constraint($builder);

        if ($result instanceof EloquentBuilder) {
            $builder = $result;
        }

        $query = $builder->getQuery();

        $this->inspectQuery($query, $model->getTable(), $stage);
        $this->guardBindings($query->getBindings(), $stage);
    }

    /**
     * A stage may depend only on the watched columns of the row: a value that moves with the clock
     * (`now()->subDays(90)`) would let a record change stage without any row changing, so the index
     * would go stale in silence.
     *
     * @param  list<mixed>  $bindings
     */
    private function guardBindings(array $bindings, SearchStage $stage): void
    {
        foreach ($bindings as $binding) {
            if ($binding instanceof \DateTimeInterface) {
                throw $this->refusal($stage, 'it compares against a date or time, but a stage must depend only on the row\'s watched columns; a time-relative stage goes stale without any row change');
            }

            if (! is_scalar($binding) && $binding !== null && ! $binding instanceof \BackedEnum) {
                throw $this->refusal($stage, 'it binds a value that is not a scalar, null or backed enum, but a stage must depend only on the row\'s watched columns');
            }
        }
    }

    private function inspectQuery(QueryBuilder $query, string $table, SearchStage $stage): void
    {
        if ($query->from !== $table) {
            throw $this->refusal($stage, sprintf('it reads from something other than the model\'s own table "%s"', $table));
        }

        if ($query->havings !== null && $query->havings !== []) {
            throw $this->refusal($stage, 'it uses a having clause, but a stage is a filter');
        }

        if ($query->limit !== null) {
            throw $this->refusal($stage, 'it sets a limit, but a stage is a filter');
        }

        if ($query->offset !== null) {
            throw $this->refusal($stage, 'it sets an offset, but a stage is a filter');
        }

        if ($query->joins !== null && $query->joins !== []) {
            throw $this->refusal($stage, 'it joins another table');
        }

        if ($query->unions !== null && $query->unions !== []) {
            throw $this->refusal($stage, 'it uses a union');
        }

        foreach ($query->wheres as $where) {
            $type = (string) $where['type'];

            switch ($type) {
                case 'Nested':
                    /** @var QueryBuilder $nested */
                    $nested = $where['query'];
                    $this->inspectQuery($nested, $table, $stage);

                    break;
                case 'Column':
                    $this->guardColumn($where['first'], $table, $stage);
                    $this->guardColumn($where['second'], $table, $stage);

                    break;
                case 'Exists':
                case 'NotExists':
                case 'Sub':
                case 'InSub':
                case 'NotInSub':
                    throw $this->refusal($stage, 'it uses a subquery, which reads another table');
                case 'raw':
                case 'Raw':
                    throw $this->refusal($stage, 'it uses raw SQL, whose columns cannot be verified');
                default:
                    if (! array_key_exists('column', $where)) {
                        throw $this->refusal($stage, sprintf('it uses a "%s" clause, whose columns cannot be verified', $type));
                    }

                    $this->guardColumn($where['column'], $table, $stage);
                    $this->guardValues($where, $stage);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function guardValues(array $where, SearchStage $stage): void
    {
        $candidates = [];

        if (array_key_exists('value', $where)) {
            $candidates[] = $where['value'];
        }

        if (array_key_exists('values', $where)) {
            $values = $where['values'];

            foreach (is_array($values) ? $values : [$values] as $value) {
                $candidates[] = $value;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate instanceof Expression || $candidate instanceof QueryBuilder || $candidate instanceof EloquentBuilder) {
                throw $this->refusal($stage, 'it compares against an expression or subquery, which can read another table');
            }
        }
    }

    private function guardColumn(mixed $column, string $table, SearchStage $stage): void
    {
        if ($column instanceof Expression || ! is_string($column)) {
            throw $this->refusal($stage, 'it filters on an expression, whose columns cannot be verified');
        }

        $segments = explode('.', $column);
        $name = array_pop($segments);

        if ($segments !== [] && implode('.', $segments) !== $table) {
            throw $this->refusal($stage, sprintf('it touches "%s", which is not the model\'s own table "%s"', $column, $table));
        }

        if (! in_array($name, $stage->watchedColumns(), true)) {
            throw $this->refusal($stage, sprintf('the column "%s" is not declared in watching()', $name));
        }
    }

    private function refusal(SearchStage $stage, string $reason): InvalidStageConstraint
    {
        return new InvalidStageConstraint(sprintf('Stage "%s" is rejected: %s.', $stage->key, $reason));
    }
}
