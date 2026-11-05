<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Indexing;

use Happenv\ModelSearch\Compilation\ReverseHop;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Illuminate\Database\Connection;

/**
 * Turns the nodes named by change-log rows into the roots whose projection they can change.
 *
 * A node whose type is a definition's morph alias IS a root key. Any other node is expanded with
 * the reverse hops of that definition's paths, one query per hop and node type for the whole batch,
 * limited per node. A node that reaches more roots than `fan_out_limit` (judged on the union over
 * all hops of its type, so the billing and the shipping address of one order cannot hide an
 * overflow in two halves) is not expanded in this batch: its roots are written back to the log as
 * root nodes, so the work spreads over later batches instead of lengthening this transaction. A
 * node of a type no registered definition knows (a trigger kept until `model-search:sync --prune`)
 * is skipped and counted.
 */
final class RootResolver
{
    /** @var array<string, array<string, list<ReverseHop>>> alias => node type => hops */
    private array $memo = [];

    public function __construct(private readonly TableNames $names) {}

    /**
     * The node types a definition reads, its own alias included.
     *
     * @return list<string>
     */
    public function nodeTypesOf(CompiledDefinition $definition): array
    {
        return [$definition->morphAlias, ...array_keys($this->hopsOf($definition))];
    }

    /**
     * @param  array<string, CompiledDefinition>  $definitions  Keyed by morph alias.
     * @param  list<array{string, string}>  $nodes  Unique (node type, node id) pairs.
     * @return array<string, list<string>> Root ids per morph alias.
     */
    public function resolve(Connection $db, array $definitions, array $nodes, DrainStats $stats): array
    {
        $byType = [];

        foreach ($nodes as [$type, $id]) {
            $byType[$type][] = $id;
        }

        $roots = [];

        foreach ($byType as $type => $ids) {
            $known = false;

            foreach ($definitions as $alias => $definition) {
                if ($type === $alias) {
                    $known = true;

                    foreach ($ids as $id) {
                        $roots[$alias][$id] = true;
                    }

                    continue;
                }

                $hops = $this->hopsOf($definition)[$type] ?? [];

                if ($hops === []) {
                    continue;
                }

                $known = true;

                foreach ($this->expand($db, $alias, $type, $ids, $hops, $stats) as $root) {
                    $roots[$alias][$root] = true;
                }
            }

            if (! $known) {
                $stats->unknownNodes += count($ids);
            }
        }

        $result = [];

        foreach ($roots as $alias => $set) {
            $result[(string) $alias] = array_map(strval(...), array_keys($set));
        }

        return $result;
    }

    /**
     * Writes root nodes back to the change log.
     *
     * @param  list<string>  $rootIds
     */
    public function spill(Connection $db, string $alias, array $rootIds): int
    {
        if ($rootIds === []) {
            return 0;
        }

        return $db->affectingStatement(
            sprintf('INSERT INTO %s ("node_type", "node_id") SELECT ?, u."id" FROM unnest(?::text[]) AS u("id")', $this->names->quoted($this->names->changes())),
            [$alias, '{'.implode(',', array_map(static fn (string $id): string => '"'.addcslashes($id, '"\\').'"', $rootIds)).'}'],
        );
    }

    /**
     * @param  list<string>  $ids
     * @param  list<ReverseHop>  $hops
     * @return list<string> The roots of the nodes that did not overflow.
     */
    private function expand(Connection $db, string $alias, string $type, array $ids, array $hops, DrainStats $stats): array
    {
        $limit = max(1, (int) config('model-search.drain.fan_out_limit', 1000));
        $perNode = [];

        foreach ($hops as $hop) {
            $query = $hop->rootsSql($type, $ids, $limit + 1);

            foreach ($db->select($query['sql'], $query['bindings']) as $row) {
                $perNode[(string) $row->node_id][(string) $row->root_id] = true;
            }
        }

        $roots = [];
        $overflowing = [];

        foreach ($perNode as $node => $set) {
            if (count($set) > $limit) {
                $overflowing[] = $node;

                continue;
            }

            foreach (array_keys($set) as $root) {
                $roots[(string) $root] = true;
            }
        }

        foreach ($overflowing as $node) {
            foreach ($hops as $hop) {
                $query = $hop->rootsSql($type, [$node], PHP_INT_MAX);
                $stats->spilledRoots += $db->affectingStatement(
                    sprintf('INSERT INTO %s ("node_type", "node_id") SELECT ?, "s"."root_id" FROM (%s) AS "s"', $this->names->quoted($this->names->changes()), $query['sql']),
                    [$alias, ...$query['bindings']],
                );
            }
        }

        return array_map(strval(...), array_keys($roots));
    }

    /**
     * @return array<string, list<ReverseHop>> Node type => hops (de-duplicated by route).
     */
    private function hopsOf(CompiledDefinition $definition): array
    {
        if (isset($this->memo[$definition->morphAlias])) {
            return $this->memo[$definition->morphAlias];
        }

        $byType = [];
        $seen = [];

        foreach ($definition->fields as $field) {
            foreach ($field->compiledPath?->reverseHops() ?? [] as $hop) {
                $identity = $hop->identity();

                if (isset($seen[$identity])) {
                    continue;
                }

                $seen[$identity] = true;
                $byType[$hop->nodeType][] = $hop;
            }
        }

        return $this->memo[$definition->morphAlias] = $byType;
    }
}
