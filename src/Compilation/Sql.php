<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Small PostgreSQL helpers shared by the compiled SQL of paths, reverse hops and stages.
 *
 * @internal
 */
final class Sql
{
    public static function connectionName(Model $model): string
    {
        return $model->getConnectionName() ?? DB::getDefaultConnection();
    }

    public static function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * A possibly schema-qualified table name, with the connection prefix applied to the table part.
     */
    public static function table(Model $model, ?string $name = null): string
    {
        $prefix = $model->getConnection()->getTablePrefix();
        $segments = explode('.', $name ?? $model->getTable());
        $last = array_pop($segments);
        $segments[] = $prefix.$last;

        return implode('.', array_map(self::ident(...), $segments));
    }

    /**
     * The soft delete column of a model, or null when it does not soft delete.
     */
    public static function softDeleteColumn(Model $model): ?string
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true) || ! method_exists($model, 'getDeletedAtColumn')) {
            return null;
        }

        $column = $model->getDeletedAtColumn();

        return is_string($column) ? $column : null;
    }

    /**
     * `<column> = ANY(?::type[])`, the binding being an array literal from {@see self::arrayLiteral()}.
     *
     * @param  string  $type  The column's own catalog type, so the comparison can use its index.
     */
    public static function anyOf(string $column, string $type): string
    {
        return sprintf('%s = ANY(?::%s[])', $column, $type);
    }

    /**
     * @param  list<string|int>  $values
     */
    public static function arrayLiteral(array $values): string
    {
        return '{'.implode(',', array_map(
            static fn (string|int $value): string => '"'.addcslashes((string) $value, '"\\').'"',
            $values,
        )).'}';
    }
}
