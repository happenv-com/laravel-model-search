<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

/**
 * A field path compiled into SQL: what the field contributes for given roots, which tables and
 * columns the contribution depends on, and how to get from a changed node back to the roots.
 */
final readonly class CompiledPath
{
    /**
     * @param  list<Step>  $steps
     * @param  list<WatchedTable>  $tables  Every table of the path except the root.
     * @param  list<ReverseHop>  $reverseHops
     * @param  list<string>  $rootColumns  Columns of the root table the path reads (including foreign keys it follows).
     */
    public function __construct(
        private PathRoot $root,
        private array $steps,
        private string $column,
        private bool $translatable,
        private array $tables,
        private array $reverseHops,
        private array $rootColumns,
    ) {}

    /**
     * The (root_id, value) pairs of the given roots, read straight from the source tables.
     *
     * @param  list<string|int>  $rootIds
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function contributionSql(array $rootIds): array
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
        } else {
            $value = $source;
        }

        $where = [Sql::anyOf(JoinChain::column('r', $this->root->keyColumn), $this->root->keyType)];

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
            'bindings' => [...$chain['bindings'], Sql::arrayLiteral($rootIds)],
        ];
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
     * order are both `table:addresses`); the drainer unions their roots. Node types are unique per
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
