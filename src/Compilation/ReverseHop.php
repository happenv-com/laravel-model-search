<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

use InvalidArgumentException;

/**
 * Finds the roots of changed nodes: joins from the root down to the node and filters on the node's key.
 * Soft deleted rows are kept on purpose, a row that was just deleted must still lead to its roots.
 */
final readonly class ReverseHop
{
    /**
     * @param  list<Step>  $steps  The joins from the root down to the node.
     * @param  string  $keyType  Catalog type name of the node's key column.
     * @param  bool  $distinct  Whether the chain can reach a node through several rows (pivot or through step, or a node key that is not unique).
     */
    public function __construct(
        public string $nodeType,
        private PathRoot $root,
        private array $steps,
        private string $nodeColumn,
        private string $keyType,
        private bool $distinct = false,
    ) {}

    /**
     * One row per (node, root): `node_id` and `root_id`, both text.
     *
     * The limit applies PER NODE, so one widely shared node (an address used by thousands of orders)
     * cannot starve the others. Where the chain can repeat a root the query needs DISTINCT, and then the limit
     * bounds the rows returned, not the work done to find them. A caller that wants to detect overflow passes `fan_out_limit + 1`
     * and treats a node that returns that many rows as overflowing.
     *
     * @param  list<string|int>  $nodeIds
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function rootsSql(string $nodeType, array $nodeIds, int $limit): array
    {
        if ($nodeType !== $this->nodeType) {
            throw new InvalidArgumentException(sprintf('This hop expands nodes of type "%s", not "%s".', $this->nodeType, $nodeType));
        }

        if ($limit < 1) {
            throw new InvalidArgumentException('The limit of a reverse hop must be at least 1.');
        }

        $chain = JoinChain::render($this->root, $this->steps, false);
        $alias = $this->steps === [] ? 'r' : 't'.count($this->steps);

        return [
            'sql' => sprintf(
                'SELECT "n"."id"::text AS "node_id", "x"."root_id" FROM unnest(?::%s[]) AS "n"("id") CROSS JOIN LATERAL (SELECT %s%s::text AS "root_id" %s WHERE %s = "n"."id" LIMIT %d) AS "x"',
                $this->keyType,
                $this->distinct ? 'DISTINCT ' : '',
                JoinChain::column('r', $this->root->keyColumn),
                $chain['sql'],
                JoinChain::column($alias, $this->nodeColumn),
                $limit,
            ),
            'bindings' => [Sql::arrayLiteral($nodeIds), ...$chain['bindings']],
        ];
    }

    /**
     * Distinct per node type and per route to it, so the billing and the shipping address of an order
     * (the same table and key) are two hops.
     */
    public function identity(): string
    {
        $chain = JoinChain::render($this->root, $this->steps, false);

        return implode('|', [$this->nodeType, $this->nodeColumn, $chain['sql'], json_encode($chain['bindings'], JSON_THROW_ON_ERROR)]);
    }
}
