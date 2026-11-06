<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

use Happenv\ModelSearch\Exceptions\InvalidFieldPath;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
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
use Illuminate\Database\QueryException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Turns a dotted field path (`billingAddress.phone`) into SQL over the source tables, using the
 * metadata of the Eloquent relations on the path.
 *
 * Global scopes are ignored while indexing, except soft deletes and the scope classes the
 * definition lists: a scope that reads the viewer would index a different set than a query sees.
 * A relation must declare its return type, so a method that is not a relation is never called.
 *
 * @internal
 */
final class PathCompiler
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<class-string>  $withScopes
     * @param  list<StepCondition>  $conditions  Restrictions of the field on the rows of the relations of its path.
     */
    public function compile(string $modelClass, string $path, bool $translatable = false, array $withScopes = [], bool $each = false, array $conditions = []): CompiledPath
    {
        $segments = explode('.', $path);
        $column = array_pop($segments);
        $conditionsByRelation = $this->conditionsByRelation($path, $segments, $conditions);
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
        $trail = '';

        foreach ($segments as $name) {
            $trail = $trail === '' ? $name : $trail.'.'.$name;
            $reached = $this->stepsOf($this->relationOf($current, $name, $path), $current, $withScopes, $connection);

            foreach ($reached as $index => [$step, $toModel, $toTable]) {
                // The condition restricts the table the relation reaches: its last step.
                if ($index === array_key_last($reached) && isset($conditionsByRelation[$trail])) {
                    $step = $step->withConditions($this->checkedConditions($path, $toModel, $toTable, $conditionsByRelation[$trail], $types));
                }

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
                        $this->addHop($hops, new ReverseHop($parentType, $root, array_slice($steps, 0, $previous), $step->fromColumn, $keyTypeAt($previous, $step->fromColumn), $this->canRepeat(array_slice($steps, 0, $previous), $parentType)));
                    }

                    $tableShapes[] = ['child', $parentType, $step->toColumn, $step];
                } else {
                    if (! $toModel instanceof Model || $step->toColumn !== $toModel->getKeyName()) {
                        $columns[$depth][$step->toColumn] = true;
                    }

                    $selfType = $typeOf($depth, $step->toColumn);
                    $this->addHop($hops, new ReverseHop($selfType, $root, [...$steps, $step], $step->toColumn, $keyTypeAt($depth, $step->toColumn), $this->canRepeat([...$steps, $step], $selfType)));
                    $tableShapes[] = ['self', $selfType, $step->toColumn, $step];
                }

                if ($step->morphColumn !== null) {
                    $columns[$depth][$step->morphColumn] = true;
                }

                if ($step->softDeleteColumn !== null) {
                    $columns[$depth][$step->softDeleteColumn] = true;
                }

                foreach ($step->conditions as $condition) {
                    $columns[$depth][$condition->column] = true;
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
        $elements = $each ? $this->listShape($path, $models[count($steps)] ?? $rootModel, $tableNames[count($steps)], $column, $types) : null;

        $tables = [];

        foreach ($tableShapes as $index => [$shape, $parentNodeType, $fk, $step]) {
            $depth = $index + 1;
            $names = array_keys($columns[$depth]);
            sort($names);
            $tables[] = new WatchedTable($tableNames[$depth], $names, $shape, $parentNodeType, $fk, $step->morphColumn, $step->morphValue, $connection, $step->quotedTable, $step->conditions);
        }

        $rootColumns = array_keys($columns[0]);
        sort($rootColumns);

        return new CompiledPath($root, $steps, $column, $translatable, $tables, array_values($hops), $rootColumns, $elements);
    }

    /**
     * How the elements of a list column are read: a json or jsonb array, or a native PostgreSQL array.
     *
     * @return 'json'|'array'
     */
    private function listShape(string $path, Model $model, string $table, string $column, ColumnTypes $types): string
    {
        $type = $types->raw($model, $table, $column);

        return match (true) {
            $type === 'json' || $type === 'jsonb' => 'json',
            str_starts_with($type, '_') => 'array',
            default => throw new InvalidSearchDefinition(sprintf('"%s" is declared with each(), but the column "%s.%s" is a %s, not a list (json, jsonb or a PostgreSQL array).', $path, $table, $column, $type)),
        };
    }

    /**
     * Checks that a relation name on a model is a supported relation, without compiling a path.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function assertRelation(string $modelClass, string $name): void
    {
        $relation = $this->relationOf(new $modelClass, $name, $name);

        if (! $relation instanceof BelongsTo) {
            throw new UnsupportedRelation(sprintf(
                'The via field "%s" of %s follows a %s, but only a BelongsTo can be served (the record holds the key of its parent). Declare the field on the model that holds the foreign key, or index the value with a key field on a path.',
                $name,
                $modelClass,
                class_basename($relation),
            ));
        }
    }

    /**
     * @param  array<string, ReverseHop>  $hops
     */
    private function addHop(array &$hops, ReverseHop $hop): void
    {
        $hops[$hop->identity()] ??= $hop;
    }

    /**
     * Whether a node can be reached by several chains of rows from the same root: through a pivot or
     * a through relation, or when the node's key is not the unique primary key.
     *
     * @param  list<Step>  $steps
     */
    private function canRepeat(array $steps, string $nodeType): bool
    {
        return str_contains($nodeType, '#') || array_any($steps, static fn (Step $step): bool => $step->multiplies);
    }

    /**
     * @return Relation<*, *, *>
     */
    private function relationOf(Model $model, string $name, string $path): Relation
    {
        $problem = sprintf('"%s" is not a relation of %s', $name, $model::class);

        if (! RelationNames::exists($model, $name)) {
            throw new InvalidFieldPath($problem.'.');
        }

        // A declared method must say that it is a relation before it is called; a resolver registered with
        // `resolveRelationUsing()` was registered as one, and what it returns is checked below.
        if (RelationNames::isDeclared($model, $name)) {
            $method = new ReflectionMethod($model, $name);
            $type = $method->getReturnType();

            if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0
                || ! $type instanceof ReflectionNamedType || ! is_a($type->getName(), Relation::class, true)) {
                throw new InvalidFieldPath($problem.' (a relation method must be public and declare its relation as the return type).');
            }
        }

        $relation = Relation::noConstraints(static fn (): mixed => $model->{$name}());

        if ($relation instanceof MorphTo) {
            throw new UnsupportedRelation(sprintf('%s::%s() is a MorphTo, which cannot be followed on a search path.', $model::class, $name));
        }

        if ($relation instanceof MorphToMany) {
            throw new UnsupportedRelation(sprintf('%s::%s() is a many-to-many morph relation, which is not supported on a search path.', $model::class, $name));
        }

        if (! $relation instanceof Relation) {
            throw new InvalidFieldPath($problem.' (it does not return a relation).');
        }

        $this->assertNoConditions($relation, $model, $name, $path);

        return $relation;
    }

    /**
     * Refuses a relation whose query does more than join the two tables on their keys: the path is compiled from the
     * keys alone, so every condition would be ignored and the index would hold rows the relation never returns.
     * Ordering does not change which rows there are, so it is accepted.
     *
     * @param  Relation<*, *, *>  $relation  Built without its key constraints.
     */
    private function assertNoConditions(Relation $relation, Model $model, string $name, string $path): void
    {
        $query = $relation->getQuery()->getQuery();
        $joins = $query->joins ?? [];

        $pivotTable = $relation instanceof BelongsToMany ? $relation->getTable() : null;

        // A pivot or through relation joins its intermediate table by itself; any other join is the relation's own.
        $intermediate = match (true) {
            $relation instanceof BelongsToMany => $relation->getTable(),
            $relation instanceof HasOneOrManyThrough => $relation->getParent()->getTable(),
            default => null,
        };

        if ($intermediate !== null && $joins !== [] && $joins[0]->table === $intermediate) {
            array_shift($joins);
        }

        $found = array_keys(array_filter([
            'where' => $query->wheres !== [],
            'join' => $joins !== [],
            'limit' => $query->limit !== null || ($query->groupLimit ?? null) !== null,
            'offset' => $query->offset !== null,
            'group by' => ($query->groups ?? []) !== [],
            'having' => ($query->havings ?? []) !== [],
            'union' => ($query->unions ?? []) !== [],
            // latestOfMany() and ofMany() pick one row per parent; their join is only added once the relation is queried.
            'one-of-many' => method_exists($relation, 'isOneOfMany') && $relation->isOneOfMany(),
            'before-query callback' => $query->beforeQueryCallbacks !== [],
            'after-query callback' => $this->builderProperty($relation, 'afterQueryCallbacks') !== [],
            'global scope added to the relation' => array_diff(array_keys($this->builderProperty($relation, 'scopes')), array_keys($relation->getRelated()->getGlobalScopes()), $relation instanceof HasOneOrManyThrough ? ['SoftDeletableHasManyThrough'] : []) !== [],
            'removed global scope' => $relation->getQuery()->removedScopes() !== [],
        ]));

        if ($found === []) {
            return;
        }

        $pivot = $pivotTable !== null && array_any($query->wheres, static fn (array $where): bool => is_string($where['column'] ?? null) && str_starts_with($where['column'], $pivotTable.'.'));

        throw new InvalidSearchDefinition(sprintf(
            '%s::%s() carries query conditions (%s), but the field path "%s" is compiled from the relation\'s keys only, so the index would hold rows the relation never returns. Use a relation without query conditions on the path and declare the condition on the field: SearchField::key(\'%s\')->where(\'%s\', \'<column>\', <value>).%s',
            $model::class,
            $name,
            implode(', ', $found),
            $path,
            $path,
            $name,
            $pivot ? ' Conditions on a pivot table are not supported, neither on the relation nor on the field.' : '',
        ));
    }

    /**
     * A protected property of the relation's Eloquent builder that has no accessor (the scopes it carries, the
     * callbacks it runs after the query).
     *
     * @param  Relation<*, *, *>  $relation
     * @return array<array-key, mixed>
     */
    private function builderProperty(Relation $relation, string $property): array
    {
        $builder = $relation->getQuery();
        $value = new ReflectionProperty($builder, $property)->getValue($builder);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  list<string>  $segments  The relation segments of the field path.
     * @param  list<StepCondition>  $conditions
     * @return array<string, list<StepCondition>> By the dotted relation name.
     */
    private function conditionsByRelation(string $path, array $segments, array $conditions): array
    {
        $known = [];
        $trail = '';

        foreach ($segments as $segment) {
            $trail = $trail === '' ? $segment : $trail.'.'.$segment;
            $known[$trail] = true;
        }

        $byRelation = [];

        foreach ($conditions as $condition) {
            if (! isset($known[$condition->relation])) {
                throw new InvalidSearchDefinition(sprintf(
                    'The field "%s" restricts the relation "%s", but that is not a relation segment of its path%s.',
                    $path,
                    $condition->relation,
                    $known === [] ? ' (the path has no relations)' : ' (the relations of the path are: '.implode(', ', array_map(strval(...), array_keys($known))).')',
                ));
            }

            $byRelation[$condition->relation][] = $condition;
        }

        return $byRelation;
    }

    /**
     * Checks every value against the type of its column and replaces it with its text form in that type, which is
     * what the projection and the triggers both compare (`column::text = 'value'`), so they always agree. PostgreSQL
     * does the conversion, once, here: a value the column cannot hold is refused instead of breaking every write.
     *
     * @param  list<StepCondition>  $conditions
     * @return list<StepCondition>
     */
    private function checkedConditions(string $path, ?Model $model, string $table, array $conditions, ColumnTypes $types): array
    {
        $checked = [];

        foreach ($conditions as $condition) {
            if (! $model instanceof Model) {
                throw new InvalidSearchDefinition(sprintf('The condition on "%s.%s" of the field "%s" cannot be applied: the step has no model.', $condition->relation, $condition->column, $path));
            }

            try {
                $types->raw($model, $table, $condition->column);
                $resolved = $types->resolved($model, Sql::table($model), $condition->column);
            } catch (InvalidFieldPath) {
                throw new InvalidSearchDefinition(sprintf('The field "%s" restricts "%s" by the column "%s", but the table "%s" that relation reaches has no such column.', $path, $condition->relation, $condition->column, $table));
            }

            $type = $resolved['name'];
            $formatted = $resolved['formatted'];

            if ($type === 'money') {
                throw new InvalidSearchDefinition(sprintf('The field "%s" restricts "%s" by the money column "%s", but the text of money depends on lc_monetary, so it cannot be compared reliably; compare a numeric column instead.', $path, $condition->relation, $condition->column));
            }

            if (! $resolved['comparable']) {
                throw new InvalidSearchDefinition(sprintf('The field "%s" restricts "%s" by the column "%s" (%s), but that type has no equality operator (no default btree operator class), so it cannot be compared.', $path, $condition->relation, $condition->column, $formatted));
            }

            $texts = [];

            foreach ($condition->values as $value) {
                $texts[] = $this->textOf($path, $condition, $type, $formatted, $value);
            }

            $checked[] = $condition->withTexts(
                $this->castedTexts($path, $condition, $formatted, $texts, $model),
                $resolved['builtIn'] ? $formatted : null,
            );
        }

        usort($checked, static fn (StepCondition $a, StepCondition $b): int => [$a->column, $a->values] <=> [$b->column, $b->values]);

        return $checked;
    }

    private function textOf(string $path, StepCondition $condition, string $type, string $formatted, bool|float|int|string $value): string
    {
        $fits = match (true) {
            $type === 'bool' => is_bool($value),
            in_array($type, ['int2', 'int4', 'int8', 'numeric', 'float4', 'float8'], true) => is_int($value) || is_float($value),
            in_array($type, ['text', 'varchar', 'bpchar', 'name', 'citext'], true) => ! is_bool($value),
            default => is_string($value),
        };

        if (! $fits) {
            throw new InvalidSearchDefinition($this->cannotHold($path, $condition, $formatted, $value));
        }

        // Without an offset after the time the instant would depend on the time zone of whoever reads the text: refuse, do not guess.
        // The time is required, so the day of a bare date ('2026-01-01') is not read as an offset.
        if (in_array($type, ['timestamptz', 'timetz'], true) && preg_match('/\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?\s*(?:z|[+-]\d{2}(?::?\d{2})?(?::?\d{2})?)\s*$/i', (string) $value) !== 1) {
            throw new InvalidSearchDefinition(sprintf('The field "%s" restricts "%s" by the column "%s" (%s) with the value %s, which has no offset (such as +00:00 or Z); the instant would depend on a time zone.', $path, $condition->relation, $condition->column, $formatted, var_export($value, true)));
        }

        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    private function cannotHold(string $path, StepCondition $condition, string $formatted, mixed $value): string
    {
        return sprintf('The field "%s" restricts "%s" by the column "%s" (%s) with the value %s, which that column cannot hold.', $path, $condition->relation, $condition->column, $formatted, var_export($value, true));
    }

    /**
     * The text form of the values in the type of the column, as PostgreSQL itself writes it, read in a fixed
     * environment (UTC, ISO dates, ISO 8601 intervals) so that it means the same in every session that parses it
     * again. One query per condition; a value the column cannot hold refuses the definition.
     *
     * @param  list<string>  $texts
     * @return list<string>
     */
    private function castedTexts(string $path, StepCondition $condition, string $formatted, array $texts, Model $model): array
    {
        $cast = $this->tryCast($model, $formatted, $texts);

        if ($cast !== null) {
            return $cast;
        }

        // Name the value that does not fit.
        foreach ($texts as $index => $text) {
            if ($this->tryCast($model, $formatted, [$text]) === null) {
                throw new InvalidSearchDefinition($this->cannotHold($path, $condition, $formatted, $condition->values[$index] ?? $text));
            }
        }

        throw new InvalidSearchDefinition($this->cannotHold($path, $condition, $formatted, implode(', ', $texts)));
    }

    /**
     * @param  list<string>  $texts
     * @return list<string>|null Null when PostgreSQL refuses one of the values.
     */
    private function tryCast(Model $model, string $formatted, array $texts): ?array
    {
        $connection = $model->getConnection();
        $select = implode(', ', array_map(static fn (int $index): string => sprintf('CAST(?::text AS %s)::text AS "v%d"', $formatted, $index), array_keys($texts)));

        // Always rolled back (to a savepoint inside the caller's transaction): a failed cast must not poison it, and
        // the settings must not outlive the check.
        $connection->beginTransaction();

        try {
            $connection->statement("SET LOCAL TimeZone = 'UTC'");
            $connection->statement("SET LOCAL DateStyle = 'ISO, YMD'");
            $connection->statement("SET LOCAL IntervalStyle = 'iso_8601'");

            return array_values(array_map(strval(...), (array) $connection->selectOne('SELECT '.$select, $texts)));
        } catch (QueryException) {
            return null;
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @param  Relation<*, *, *>  $relation
     * @param  list<class-string>  $withScopes
     * @return list<array{Step, Model|null, string}> The step, the model reached (null for a pivot) and its table name.
     */
    private function stepsOf(Relation $relation, Model $from, array $withScopes, string $connection): array
    {
        if ($relation instanceof BelongsTo) {
            return [$this->step($relation->getRelated(), $relation->getForeignKeyName(), $relation->getOwnerKeyName(), 'self', $withScopes, $connection)];
        }

        if ($relation instanceof HasOneOrMany) {
            $morphColumn = null;
            $morphValue = null;

            if ($relation instanceof MorphOneOrMany) {
                $segments = explode('.', $relation->getMorphType());
                $morphColumn = end($segments);
                $morphValue = $relation->getMorphClass();
            }

            return [$this->step($relation->getRelated(), $relation->getLocalKeyName(), $relation->getForeignKeyName(), 'child', $withScopes, $connection, $morphColumn, $morphValue)];
        }

        if ($relation instanceof HasOneOrManyThrough) {
            $through = $relation->getParent();

            return [
                $this->step($through, $relation->getLocalKeyName(), $relation->getFirstKeyName(), 'child', $withScopes, $connection, multiplies: true),
                $this->step($relation->getRelated(), $relation->getSecondLocalKeyName(), $relation->getForeignKeyName(), 'child', $withScopes, $connection, multiplies: true),
            ];
        }

        if ($relation instanceof BelongsToMany) {
            $pivot = $relation->getTable();
            $pivotStep = new Step(Sql::table($from, $pivot), $relation->getParentKeyName(), $relation->getForeignPivotKeyName(), 'child', null, null, null, null, true);

            return [
                [$pivotStep, null, $pivot],
                $this->step($relation->getRelated(), $relation->getRelatedPivotKeyName(), $relation->getRelatedKeyName(), 'self', $withScopes, $connection),
            ];
        }

        throw new UnsupportedRelation(sprintf('%s cannot be followed on a search path.', $relation::class));
    }

    /**
     * @param  list<class-string>  $withScopes
     * @return array{Step, Model, string}
     */
    private function step(Model $related, string $fromColumn, string $toColumn, string $shape, array $withScopes, string $connection, ?string $morphColumn = null, ?string $morphValue = null, bool $multiplies = false): array
    {
        if (Sql::connectionName($related) !== $connection) {
            throw new UnsupportedRelation(sprintf('%s is on the connection "%s", but the path runs on "%s"; a path cannot cross connections.', $related::class, Sql::connectionName($related), $connection));
        }

        return $this->buildStep($related, $fromColumn, $toColumn, $shape, $withScopes, $morphColumn, $morphValue, $multiplies);
    }

    /**
     * @param  list<class-string>  $withScopes
     * @return array{Step, Model, string}
     */
    private function buildStep(Model $related, string $fromColumn, string $toColumn, string $shape, array $withScopes, ?string $morphColumn = null, ?string $morphValue = null, bool $multiplies = false): array
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
            multiplies: $multiplies,
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

        return [
            'sql' => $base->toSql(),
            'bindings' => BindingGuard::canonical($base->getBindings(), sprintf('A global scope of %s that the definition lists', $model::class)),
        ];
    }
}
