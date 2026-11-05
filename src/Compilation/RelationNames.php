<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The one rule that says whether a name on a model may be a relation: a declared method, or a relation
 * registered at runtime with `Model::resolveRelationUsing()` (on the model or one of its parents), which is
 * how modular extensions add relations to a model they do not own.
 *
 * A declared method is only trusted when it declares a Relation return type (the path compiler checks it
 * before calling it); a registered resolver was registered as a relation, so it is safe to call, and what it
 * returns must still be a Relation.
 *
 * @internal
 */
final class RelationNames
{
    /**
     * Whether the name is a method of the model, or has a relation resolver registered for it.
     */
    public static function exists(Model $model, string $name): bool
    {
        return self::isDeclared($model, $name) || self::isRegistered($model, $name);
    }

    /**
     * Whether the name is a method declared in the model's class hierarchy (which wins over a resolver).
     */
    public static function isDeclared(Model $model, string $name): bool
    {
        return method_exists($model, $name);
    }

    public static function isRegistered(Model $model, string $name): bool
    {
        return $model->relationResolver($model::class, $name) !== null;
    }

    /**
     * The relation behind a name, or null when the name is neither a method nor a registered resolver, or
     * when it does not produce a relation.
     *
     * @return Relation<*, *, *>|null
     */
    public static function find(Model $model, string $name): ?Relation
    {
        if (! self::exists($model, $name)) {
            return null;
        }

        $relation = $model->{$name}();

        return $relation instanceof Relation ? $relation : null;
    }
}
