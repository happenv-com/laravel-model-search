<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

/**
 * A field path compiled into SQL: what the field contributes for given roots, which tables and
 * columns the contribution depends on, and how to get from a changed node back to the roots.
 *
 * @internal
 */
final readonly class CompiledPath
{
    /**
     * @param  list<Step>  $steps
     * @param  list<WatchedTable>  $tables  Every table of the path except the root.
     * @param  list<ReverseHop>  $reverseHops
     * @param  list<string>  $rootColumns  Columns of the root table the path reads (including foreign keys it follows).
     * @param  'json'|'array'|null  $elements  When the column holds a list: how it is read, one value per element.
     */
    public function __construct(
        private PathRoot $root,
        private array $steps,
        private string $column,
        private bool $translatable,
        private array $tables,
        private array $reverseHops,
        private array $rootColumns,
        private ?string $elements = null,
    ) {}

    /**
     * The (root_id, value) pairs of the given roots, read straight from the source tables.
     * `null` reads every root (a scan, for callers that filter on the value instead).
     *
     * @param  list<string|int>|null  $rootIds
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function contributionSql(?array $rootIds): array
    {
        $chain = JoinChain::render($this->root, $this->steps, true);
        $alias = $this->steps === [] ? 'r' : 't'.count($this->steps);
        $source = JoinChain::column($alias, $this->column);
        $from = $chain['sql'];

        if ($this->translatable) {
            $from .= sprintf(
                ' CROSS JOIN LATERAL jsonb_each_text(CASE WHEN jsonb_typeof(%1$s::jsonb) = \'object\' THEN %1$s::jsonb ELSE \'{}\'::jsonb END) AS "tr"("locale", "value")',
                $source,
            );
            $value = '"tr"."value"';
        } elseif ($this->elements !== null) {
            $from .= $this->elementsJoin($source);
            $value = '"el"."value"';
        } else {
            $value = $source;
        }

        $where = $rootIds === null ? [] : [Sql::anyOf(JoinChain::column('r', $this->root->keyColumn), $this->root->keyType)];

        if ($this->root->softDeleteColumn !== null) {
            $where[] = JoinChain::column('r', $this->root->softDeleteColumn).' IS NULL';
        }

        $where[] = $value.' IS NOT NULL';

        return [
            'sql' => sprintf(
                'SELECT DISTINCT %s::text AS "root_id", %s::text AS "value" %s WHERE %s',
                JoinChain::column('r', $this->root->keyColumn),
                $value,
                $from,
                implode(' AND ', $where),
            ),
            'bindings' => [...$chain['bindings'], ...($rootIds === null ? [] : [Sql::arrayLiteral($rootIds)])],
        ];
    }

    /**
     * One row per element of the list column, the first `each.max_per_record` in array order. A json list keeps
     * strings and numbers (a number as its text); a native array keeps every non-null element as text.
     */
    private function elementsJoin(string $source): string
    {
        $cap = max(0, (int) config('model-search.each.max_per_record', 50));

        if ($this->elements === 'array') {
            return sprintf(
                ' CROSS JOIN LATERAL (SELECT "e"."v"::text AS "value" FROM unnest(%s) WITH ORDINALITY AS "e"("v", "n") WHERE "e"."n" <= %d) AS "el"',
                $source,
                $cap,
            );
        }

        return sprintf(
            ' CROSS JOIN LATERAL (SELECT "e"."v" #>> \'{}\' AS "value" FROM jsonb_array_elements(CASE WHEN jsonb_typeof(%1$s::jsonb) = \'array\' THEN %1$s::jsonb ELSE \'[]\'::jsonb END) WITH ORDINALITY AS "e"("v", "n") WHERE "e"."n" <= %2$d AND jsonb_typeof("e"."v") IN (\'string\', \'number\')) AS "el"',
            $source,
            $cap,
        );
    }

    /**
     * @return list<WatchedTable>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /**
     * The hops from a changed node back to the roots, one per (node type, route).
     *
     * Contract for the drainer: a node whose type is the root's morph alias is a root primary key and
     * has no hop. Several hops may share a node type (the billing and the shipping address of an
     * order are both `table:addresses`); the drainer unions their roots, and fan-out overflow is judged
     * on the UNION of roots over all hops of the same node type, not per hop. Node types are unique per
     * (table, key column): `table:<name>` for a primary key, `table:<name>#<column>` for any other
     * column, `<alias>#<column>` for a non-primary key of the root.
     *
     * @return list<ReverseHop>
     */
    public function reverseHops(): array
    {
        return $this->reverseHops;
    }

    /**
     * @return list<string>
     */
    public function rootColumns(): array
    {
        return $this->rootColumns;
    }
}
