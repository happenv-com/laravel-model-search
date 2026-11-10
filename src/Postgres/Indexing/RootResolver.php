<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Indexing;

use Happenv\ModelSearch\Compilation\ReverseHop;
use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Postgres\Schema\NodeKey;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Turns the nodes named by change-log rows into the roots whose projection they can change.
 *
 * A node whose type is a definition's morph alias IS a root key. Any other node is expanded with
 * the reverse hops of that definition's paths, one query per hop, node type and chunk of nodes,
 * limited per node. A node that reaches more roots than `fan_out_limit` (judged on the union over
 * all hops of its type, so the billing and the shipping address of one order cannot hide an
 * overflow in two halves) is not expanded in this batch: its roots are written back to the log as
 * root nodes, so the work spreads over later batches instead of lengthening this transaction.
 *
 * Memory and transaction length stay bounded: once the roots gathered reach the batch cap, the
 * nodes not yet expanded are handed back unresolved (the caller leaves their log rows where they are).
 * Every expansion runs in its own savepoint; a node that cannot be expanded (an id that does not
 * cast to the key type) is counted and dropped without costing the others.
 *
 * @internal
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
     * @param  array<string, CompiledDefinition>  $definitions  Keyed by morph alias; the ones the caller may apply.
     * @param  list<array{string, string}>  $nodes  Unique (node type, node id) pairs.
     * @param  int  $cap  Stop expanding once this many roots have been gathered.
     * @return array{roots: array<string, list<string>>, deferred: list<array{string, string}>} Root ids per morph alias, and the nodes left unexpanded.
     */
    public function resolve(Connection $db, array $definitions, array $nodes, DrainStats $stats, int $cap): array
    {
        $byType = [];

        foreach ($nodes as [$type, $id]) {
            $byType[$type][] = $id;
        }

        $chunkSize = max(1, min(100, intdiv($cap, 2)));
        $roots = [];
        $gathered = 0;
        $deferred = [];

        foreach ($byType as $type => $ids) {
            $type = (string) $type;
            $readers = array_filter($definitions, fn (CompiledDefinition $definition, string $alias): bool => $type === $alias || isset($this->hopsOf($definition)[$type]), ARRAY_FILTER_USE_BOTH);

            if ($readers === []) {
                $stats->unknownNodes += count($ids);

                continue;
            }

            foreach (array_chunk($ids, $chunkSize) as $chunk) {
                if ($gathered >= $cap) {
                    foreach ($chunk as $id) {
                        $deferred[] = [$type, $id];
                    }

                    continue;
                }

                foreach ($readers as $alias => $definition) {
                    $found = $type === $alias ? $chunk : $this->expand($db, (string) $alias, $type, $chunk, $this->hopsOf($definition)[$type], $stats);

                    foreach ($found as $root) {
                        if (! isset($roots[$alias][$root])) {
                            $roots[$alias][$root] = true;
                            $gathered++;
                        }
                    }
                }
            }
        }

        $result = [];

        foreach ($roots as $alias => $set) {
            $result[(string) $alias] = array_map(strval(...), array_keys($set));
        }

        return ['roots' => $result, 'deferred' => $deferred];
    }

    /**
     * Writes root nodes back to the log.
     *
     * @param  list<string>  $rootIds
     */
    public function spill(Connection $db, string $alias, array $rootIds): int
    {
        if ($rootIds === []) {
            return 0;
        }

        return $db->affectingStatement(
            sprintf('INSERT INTO %s ("node_type", "node_id") SELECT ?, u."id" FROM unnest(%s) AS u("id")', $this->names->quoted($this->names->changes()), NodeKey::arrayParameter()),
            [$alias, Sql::arrayLiteral($rootIds)],
        );
    }

    /**
     * Expands nodes in a savepoint. When it fails the nodes are retried in halves, down to one, so
     * a single malformed id costs only itself.
     *
     * @param  list<string>  $ids
     * @param  list<ReverseHop>  $hops
     * @return list<string>
     */
    private function expand(Connection $db, string $alias, string $type, array $ids, array $hops, DrainStats $stats): array
    {
        try {
            return $db->transaction(fn (): array => $this->expandAll($db, $alias, $type, $ids, $hops, $stats));
        } catch (Throwable $failure) {
            if (PoisonRecords::isTransient($failure)) {
                throw $failure;
            }
        }

        if (count($ids) === 1) {
            $stats->unresolvableNodes++;

            return [];
        }

        $found = [];

        foreach (array_chunk($ids, max(1, intdiv(count($ids) + 1, 2))) as $half) {
            array_push($found, ...$this->expand($db, $alias, $type, $half, $hops, $stats));
        }

        return $found;
    }

    /**
     * @param  list<string>  $ids
     * @param  list<ReverseHop>  $hops
     * @return list<string> The roots of the nodes that did not overflow.
     */
    private function expandAll(Connection $db, string $alias, string $type, array $ids, array $hops, DrainStats $stats): array
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
                    sprintf('INSERT INTO %s ("node_type", "node_id") SELECT ?, %s FROM (%s) AS "s"', $this->names->quoted($this->names->changes()), NodeKey::cast('"s"."root_id"'), $query['sql']),
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
