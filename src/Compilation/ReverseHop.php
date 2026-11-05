<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

use InvalidArgumentException;

/**
 * Finds the roots of a changed node: joins from the root down to the node and filters on the node's key.
 * Soft deleted rows are kept on purpose, a row that was just deleted must still lead to its roots.
 */
final readonly class ReverseHop
{
    /**
     * @param  list<Step>  $steps  The joins from the root down to the node.
     * @param  'uuid'|'bigint'|'text'  $keyType
     */
    public function __construct(
        public string $nodeType,
        private PathRoot $root,
        private array $steps,
        private string $nodeColumn,
        private string $keyType,
    ) {}

    /**
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
                'SELECT DISTINCT %s::text AS "root_id" %s WHERE %s LIMIT %d',
                JoinChain::column('r', $this->root->keyColumn),
                $chain['sql'],
                Sql::anyOf(JoinChain::column($alias, $this->nodeColumn), $this->keyType),
                $limit,
            ),
            'bindings' => [...$chain['bindings'], Sql::arrayLiteral($nodeIds)],
        ];
    }

    public function identity(): string
    {
        return implode('|', [$this->nodeType, count($this->steps), $this->nodeColumn]);
    }
}
