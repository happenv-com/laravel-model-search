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
    }

    private function inspectQuery(QueryBuilder $query, string $table, SearchStage $stage): void
    {
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
                    throw $this->refusal($stage, 'it uses a subquery, which reads another table');
                case 'raw':
                case 'Raw':
                    throw $this->refusal($stage, 'it uses raw SQL, whose columns cannot be verified');
                default:
                    if (! array_key_exists('column', $where)) {
                        throw $this->refusal($stage, sprintf('it uses a "%s" clause, whose columns cannot be verified', $type));
                    }

                    $this->guardColumn($where['column'], $table, $stage);
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
