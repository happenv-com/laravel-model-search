<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

use Happenv\ModelSearch\Exceptions\InvalidFieldPath;
use Happenv\ModelSearch\Exceptions\UnsupportedRelation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Turns a dotted field path (`billingAddress.phone`) into SQL over the source tables, using the
 * metadata of the Eloquent relations on the path.
 *
 * Global scopes are ignored while indexing, except soft deletes and the scope classes the
 * definition lists: a scope that reads the viewer would index a different set than a query sees.
 * A relation must declare its return type, so a method that is not a relation is never called.
 */
final class PathCompiler
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<class-string>  $withScopes
     */
    public function compile(string $modelClass, string $path, bool $translatable = false, array $withScopes = []): CompiledPath
    {
        $segments = explode('.', $path);
        $column = array_pop($segments);
        $rootModel = new $modelClass;
        $types = new ColumnTypes;
        $connection = Sql::connectionName($rootModel);

        $root = new PathRoot(
            quotedTable: Sql::table($rootModel),
            keyColumn: $rootModel->getKeyName(),
            keyType: $types->of($rootModel, $rootModel->getTable(), $rootModel->getKeyName()),
            softDeleteColumn: Sql::softDeleteColumn($rootModel),
            derived: $this->derivedTable($rootModel, $withScopes),
            nodeType: $rootModel->getMorphClass(),
            connection: $connection,
        );

        /** @var list<Step> $steps */
        $steps = [];
        /** @var list<Model|null> $models The model of every depth; null for a pivot table. */
        $models = [$rootModel];
        /** @var list<string> $tableNames */
        $tableNames = [$rootModel->getTable()];
        /** @var list<array<string, true>> $columns */
        $columns = [[]];
        /** @var array<string, ReverseHop> $hops */
        $hops = [];
        /** @var list<array{'child'|'self', string, string, Step}> $tableShapes shape, parent node type, fk column, step */
        $tableShapes = [];

        // The node a key column of the table at a depth identifies: the primary key keeps the plain type
        // (the morph alias for the root, `table:<name>` otherwise); any other column is a distinct node.
        $typeOf = static function (int $at, string $key) use ($root, &$models, &$tableNames): string {
            $base = $at === 0 ? $root->nodeType : 'table:'.($tableNames[$at] ?? $root->nodeType);
            $model = $models[$at];

            return $model instanceof Model && $key === $model->getKeyName() ? $base : $base.'#'.$key;
        };
        $keyTypeAt = static function (int $at, string $key) use ($types, $rootModel, &$models, &$tableNames): string {
            return $types->of($models[$at] ?? $rootModel, $tableNames[$at], $key);
        };

        $current = $rootModel;

        foreach ($segments as $name) {
            foreach ($this->stepsOf($this->relationOf($current, $name), $current, $withScopes) as [$step, $toModel, $toTable]) {
                $depth = count($steps) + 1;
                $previous = $depth - 1;
                $previousModel = $models[$previous];
                $models[] = $toModel;
                $tableNames[] = $toTable;

                if (! $previousModel instanceof Model || $step->fromColumn !== $previousModel->getKeyName()) {
                    $columns[$previous][$step->fromColumn] = true;
                }

                $columns[$depth] = [];

                if ($step->shape === 'child') {
                    $columns[$depth][$step->toColumn] = true;
                    $parentType = $typeOf($previous, $step->fromColumn);

                    if ($previous > 0 || $step->fromColumn !== $root->keyColumn) {
                        $this->addHop($hops, new ReverseHop($parentType, $root, array_slice($steps, 0, $previous), $step->fromColumn, $keyTypeAt($previous, $step->fromColumn)));
                    }

                    $tableShapes[] = ['child', $parentType, $step->toColumn, $step];
                } else {
                    if (! $toModel instanceof Model || $step->toColumn !== $toModel->getKeyName()) {
                        $columns[$depth][$step->toColumn] = true;
                    }

                    $selfType = $typeOf($depth, $step->toColumn);
                    $this->addHop($hops, new ReverseHop($selfType, $root, [...$steps, $step], $step->toColumn, $keyTypeAt($depth, $step->toColumn)));
                    $tableShapes[] = ['self', $selfType, $step->toColumn, $step];
                }

                if ($step->morphColumn !== null) {
                    $columns[$depth][$step->morphColumn] = true;
                }

                if ($step->softDeleteColumn !== null) {
                    $columns[$depth][$step->softDeleteColumn] = true;
                }

                $steps[] = $step;
            }

            $last = $models[count($models) - 1];

            if (! $last instanceof Model) {
                throw new InvalidFieldPath(sprintf('"%s" ends on a pivot table, which cannot hold a searchable value.', $path));
            }

            $current = $last;
        }

        $columns[count($steps)][$column] = true;

        $tables = [];

        foreach ($tableShapes as $index => [$shape, $parentNodeType, $fk, $step]) {
            $depth = $index + 1;
            $names = array_keys($columns[$depth]);
            sort($names);
            $tables[] = new WatchedTable($tableNames[$depth], $names, $shape, $parentNodeType, $fk, $step->morphColumn, $step->morphValue, $connection, $step->quotedTable);
        }

        $rootColumns = array_keys($columns[0]);
        sort($rootColumns);

        return new CompiledPath($root, $steps, $column, $translatable, $tables, array_values($hops), $rootColumns);
    }

    /**
     * Checks that a relation name on a model is a supported relation, without compiling a path.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function assertRelation(string $modelClass, string $name): void
    {
        $this->relationOf(new $modelClass, $name);
    }

    /**
     * @param  array<string, ReverseHop>  $hops
     */
    private function addHop(array &$hops, ReverseHop $hop): void
    {
        $hops[$hop->identity()] ??= $hop;
    }

    /**
     * @return Relation<*, *, *>
     */
    private function relationOf(Model $model, string $name): Relation
    {
        $problem = sprintf('"%s" is not a relation of %s', $name, $model::class);

        if (! method_exists($model, $name)) {
            throw new InvalidFieldPath($problem.'.');
        }

        $method = new ReflectionMethod($model, $name);
        $type = $method->getReturnType();

        if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0
            || ! $type instanceof ReflectionNamedType || ! is_a($type->getName(), Relation::class, true)) {
            throw new InvalidFieldPath($problem.' (a relation method must be public and declare its relation as the return type).');
        }

        $relation = Relation::noConstraints(static fn (): mixed => $model->{$name}());

        if ($relation instanceof MorphTo) {
            throw new UnsupportedRelation(sprintf('%s::%s() is a MorphTo, which cannot be followed on a search path.', $model::class, $name));
        }

        if ($relation instanceof MorphToMany) {
            throw new UnsupportedRelation(sprintf('%s::%s() is a many-to-many morph relation, which is not supported on a search path.', $model::class, $name));
        }

        if (! $relation instanceof Relation) {
            throw new InvalidFieldPath($problem.'.');
        }

        return $relation;
    }

    /**
     * @param  Relation<*, *, *>  $relation
     * @param  list<class-string>  $withScopes
     * @return list<array{Step, Model|null, string}> The step, the model reached (null for a pivot) and its table name.
     */
    private function stepsOf(Relation $relation, Model $from, array $withScopes): array
    {
        if ($relation instanceof BelongsTo) {
            return [$this->step($relation->getRelated(), $relation->getForeignKeyName(), $relation->getOwnerKeyName(), 'self', $withScopes)];
        }

        if ($relation instanceof HasOneOrMany) {
            $morphColumn = null;
            $morphValue = null;

            if ($relation instanceof MorphOneOrMany) {
                $segments = explode('.', $relation->getMorphType());
                $morphColumn = end($segments);
                $morphValue = $relation->getMorphClass();
            }

            return [$this->step($relation->getRelated(), $relation->getLocalKeyName(), $relation->getForeignKeyName(), 'child', $withScopes, $morphColumn, $morphValue)];
        }

        if ($relation instanceof HasOneOrManyThrough) {
            $through = $relation->getParent();

            return [
                $this->step($through, $relation->getLocalKeyName(), $relation->getFirstKeyName(), 'child', $withScopes),
                $this->step($relation->getRelated(), $relation->getSecondLocalKeyName(), $relation->getForeignKeyName(), 'child', $withScopes),
            ];
        }

        if ($relation instanceof BelongsToMany) {
            $pivot = $relation->getTable();
            $pivotStep = new Step(Sql::table($from, $pivot), $relation->getParentKeyName(), $relation->getForeignPivotKeyName(), 'child', null, null, null, null);

            return [
                [$pivotStep, null, $pivot],
                $this->step($relation->getRelated(), $relation->getRelatedPivotKeyName(), $relation->getRelatedKeyName(), 'self', $withScopes),
            ];
        }

        throw new UnsupportedRelation(sprintf('%s cannot be followed on a search path.', $relation::class));
    }

    /**
     * @param  list<class-string>  $withScopes
     * @return array{Step, Model, string}
     */
    private function step(Model $related, string $fromColumn, string $toColumn, string $shape, array $withScopes, ?string $morphColumn = null, ?string $morphValue = null): array
    {
        /** @var 'child'|'self' $shape */
        $step = new Step(
            quotedTable: Sql::table($related),
            fromColumn: $fromColumn,
            toColumn: $toColumn,
            shape: $shape,
            morphColumn: $morphColumn,
            morphValue: $morphValue,
            softDeleteColumn: Sql::softDeleteColumn($related),
            derived: $this->derivedTable($related, $withScopes),
        );

        return [$step, $related, $related->getTable()];
    }

    /**
     * The table seen through the global scopes the definition lists (soft deletes are handled separately).
     *
     * @param  list<class-string>  $withScopes
     * @return array{sql: string, bindings: list<mixed>}|null
     */
    private function derivedTable(Model $model, array $withScopes): ?array
    {
        $listed = [];

        foreach ($model->getGlobalScopes() as $key => $scope) {
            if ($key !== SoftDeletingScope::class && in_array($key, $withScopes, true)) {
                $listed[$key] = $scope;
            }
        }

        if ($listed === []) {
            return null;
        }

        $query = $model->newQueryWithoutScopes();

        foreach ($listed as $key => $scope) {
            $query->withGlobalScope($key, $scope);
        }

        $base = $query->applyScopes()->toBase();

        return ['sql' => $base->toSql(), 'bindings' => $base->getBindings()];
    }
}
