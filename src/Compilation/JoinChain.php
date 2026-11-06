<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

/**
 * Renders `FROM <root> r JOIN … t1 JOIN … t2` for a path, aliasing the root `r` and the steps `t1…tN`.
 *
 * @internal
 */
final class JoinChain
{
    /**
     * @param  list<Step>  $steps
     * @param  bool  $restricted  Whether soft deleted rows, the listed global scopes and the conditions of the field are
     *                            left out (indexing) or kept (expanding changed nodes, where a row that just left,
     *                            or stopped matching, must still lead to its roots).
     * @return array{sql: string, bindings: list<mixed>}
     */
    public static function render(PathRoot $root, array $steps, bool $restricted): array
    {
        $bindings = [];
        $sql = 'FROM ';

        if ($restricted && $root->derived !== null) {
            $sql .= '('.$root->derived['sql'].')';
            array_push($bindings, ...$root->derived['bindings']);
        } else {
            $sql .= $root->quotedTable;
        }

        $sql .= ' AS "r"';
        $previous = 'r';

        foreach ($steps as $index => $step) {
            $alias = 't'.($index + 1);
            $on = sprintf('%s = %s', self::column($alias, $step->toColumn), self::column($previous, $step->fromColumn));

            if ($restricted && $step->derived !== null) {
                $table = '('.$step->derived['sql'].')';
                array_push($bindings, ...$step->derived['bindings']);
            } else {
                $table = $step->quotedTable;
            }

            if ($step->morphColumn !== null) {
                $on .= sprintf(' AND %s = ?', self::column($alias, $step->morphColumn));
                $bindings[] = $step->morphValue;
            }

            if ($restricted && $step->softDeleteColumn !== null) {
                $on .= sprintf(' AND %s IS NULL', self::column($alias, $step->softDeleteColumn));
            }

            if ($restricted) {
                foreach ($step->conditions as $condition) {
                    // The same comparison the triggers make: typed for a built-in type, as text for any other.
                    $column = self::column($alias, $condition->column);
                    $placeholder = $condition->typedAs === null ? '?' : sprintf('CAST(? AS %s)', $condition->typedAs);
                    $column .= $condition->typedAs === null ? '::text' : '';
                    $on .= count($condition->values) === 1
                        ? sprintf(' AND %s = %s', $column, $placeholder)
                        : sprintf(' AND %s IN (%s)', $column, implode(', ', array_fill(0, count($condition->values), $placeholder)));
                    array_push($bindings, ...$condition->values);
                }
            }

            $sql .= sprintf(' JOIN %s AS %s ON %s', $table, Sql::ident($alias), $on);
            $previous = $alias;
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    public static function column(string $alias, string $column): string
    {
        return Sql::ident($alias).'.'.Sql::ident($column);
    }
}
