<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Indexing;

use Happenv\ModelSearch\Compilation\Fingerprint;
use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Events\SearchIndexChanged;
use Happenv\ModelSearch\Events\SearchIndexLagging;
use Happenv\ModelSearch\Jobs\DrainSearchIndex;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use stdClass;
use Throwable;

/**
 * Keeps the projection (entries and keys) in step with the source tables.
 *
 * The package does not schedule it: the host runs `model-search:drain` or dispatches
 * {@see DrainSearchIndex}. Production runs it asynchronously, at least
 * once and in no particular order, so every step is idempotent and convergent: a batch recomputes
 * what the projection of the touched roots SHOULD be from the current source rows and writes only
 * the difference, so a repeated, reordered or concurrent change cannot leave it in a different state.
 *
 * Every batch is one transaction that starts with a transaction-scoped advisory lock (a session
 * lock would travel with the backend under a pooler); the loser of that race ends its drain at once.
 * Writers are never blocked: the triggers only INSERT into the change log, and a batch deletes only
 * the log rows it read. A drain alternates one batch of the change log with one batch of a pending
 * rebuild, so a rebuild cannot starve live changes and live changes cannot starve a rebuild.
 */
final class SearchIndexer
{
    private ?Contributions $contributions = null;

    private ?RootResolver $resolver = null;

    /** @var array<string, string> */
    private array $fingerprints = [];

    public function __construct(
        private readonly ModelSearchRegistry $registry,
        private readonly IndexState $state,
        private readonly ?string $connection = null,
    ) {}

    /**
     * Processes the change log and pending rebuilds until there is nothing left, another drainer
     * holds the lock, or the time budget (milliseconds, `drain.budget_ms` by default) is used up.
     * At least one round always runs, so a budget of 0 means exactly one round.
     */
    public function drain(?int $budgetMs = null): DrainStats
    {
        $stats = new DrainStats;
        $definitions = $this->definitions();

        if ($definitions === []) {
            return $stats;
        }

        $budget = $budgetMs ?? (int) config('model-search.drain.budget_ms', 50000);
        $deadline = hrtime(true) + max(0, $budget) * 1_000_000;

        $this->watchLag($stats);
        $this->switchOversizedTypes($definitions, $stats);
        $this->poison()->requeueDue($this->db(), array_keys($definitions));

        do {
            $log = $this->logBatch($definitions, $stats);
            $rebuild = $log === null ? null : $this->rebuildBatch($definitions, $stats);

            if ($log === null || $rebuild === null) {
                $stats->lockedOut = true;

                break;
            }
        } while (($log || $rebuild) && hrtime(true) < $deadline);

        return $stats;
    }

    /**
     * Drains everything that is pending right now, without a time budget. For tests and for
     * synchronous queues: the triggers have filled the log, this brings the projection up to date.
     */
    public function drainNow(): DrainStats
    {
        return $this->drain(86_400_000);
    }

    /**
     * Sends types back to the start of a rebuild (all installed types when none are named).
     * A type that was never installed is left alone: a rebuild never installs.
     *
     * @param  list<string>|null  $subjectTypes  Morph aliases.
     * @return list<string> The types that were marked.
     */
    public function rebuild(?array $subjectTypes = null): array
    {
        return $this->state->markForRebuild($subjectTypes ?? array_keys($this->definitions()));
    }

    /**
     * Seconds the oldest unprocessed change has been waiting; null when the log is empty.
     */
    public function lag(): ?int
    {
        $row = $this->db()->selectOne(sprintf(
            'SELECT EXTRACT(EPOCH FROM clock_timestamp() - "changed_at") AS "seconds" FROM %s ORDER BY "id" LIMIT 1',
            $this->names()->quoted($this->names()->changes()),
        ));

        return $row === null ? null : max(0, (int) floor((float) $row->seconds));
    }

    /**
     * Puts failed records back into the change log. Their attempt counter starts over.
     *
     * @param  list<string>|null  $subjectTypes
     * @param  list<string>|null  $subjectIds
     */
    public function requeueFailures(?array $subjectTypes = null, ?array $subjectIds = null): int
    {
        return $this->poison()->requeue($this->db(), $subjectTypes, $subjectIds);
    }

    /**
     * The key of the advisory lock every batch takes (a bigint derived from the table prefix).
     */
    public function lockKey(): int
    {
        return (int) hexdec(substr(md5('model-search:drain:'.$this->names()->prefix()), 0, 15));
    }

    /**
     * One batch of the change log. Null when another drainer holds the lock, false when the log
     * was empty, true when rows were processed.
     *
     * @param  array<string, CompiledDefinition>  $definitions
     */
    private function logBatch(array $definitions, DrainStats $total): ?bool
    {
        $batch = new DrainStats;
        $db = $this->db();
        $changes = $this->names()->quoted($this->names()->changes());

        $outcome = $db->transaction(function () use ($db, $definitions, $changes, $batch): ?bool {
            if (! $this->lock($db)) {
                return null;
            }

            $rows = $db->select(sprintf('SELECT "id", "node_type", "node_id" FROM %s ORDER BY "id" LIMIT %d', $changes, max(1, (int) config('model-search.drain.rows', 5000))));

            if ($rows === []) {
                return false;
            }

            [$consumed, $nodes] = $this->dedupe($rows);

            $batch->logBatches++;
            $batch->logRowsRead = count($consumed);
            $batch->nodes = count($nodes);

            $cap = max((int) config('model-search.drain.rows', 5000), (int) config('model-search.drain.nodes', 1000), 1);

            foreach ($this->resolver()->resolve($db, $definitions, $nodes, $batch) as $alias => $rootIds) {
                if (count($rootIds) > $cap) {
                    $batch->spilledRoots += $this->resolver()->spill($db, $alias, array_slice($rootIds, $cap));
                    $rootIds = array_slice($rootIds, 0, $cap);
                }

                $this->reindex($definitions[$alias], $rootIds, $batch);
            }

            $db->affectingStatement(sprintf('DELETE FROM %s WHERE "id" = ANY(?::bigint[])', $changes), ['{'.implode(',', $consumed).'}']);

            return true;
        });

        $this->announce($batch);
        $total->merge($batch);

        return $outcome;
    }

    /**
     * One batch of the first pending rebuild this release may run. Null when another drainer holds
     * the lock, false when nothing is pending, true when a batch ran.
     *
     * @param  array<string, CompiledDefinition>  $definitions
     */
    private function rebuildBatch(array $definitions, DrainStats $total): ?bool
    {
        $candidate = $this->nextRebuild($definitions);

        if (! $candidate instanceof CompiledDefinition) {
            return false;
        }

        $definition = $candidate;
        $batch = new DrainStats;
        $db = $this->db();

        $outcome = $db->transaction(function () use ($db, $definition, $batch): ?bool {
            if (! $this->lock($db)) {
                return null;
            }

            $type = $definition->morphAlias;
            $row = $this->state->find($type);
            $cursor = $row['rebuild_cursor'] ?? null;

            if ($row === null || $cursor === null) {
                return false;
            }

            $limit = max(1, (int) config('model-search.drain.rows', 5000));
            $batch->rebuildBatches++;

            if ($cursor === '' && $this->sweep($db, $definition, $limit, $batch) >= $limit) {
                return true;
            }

            $ids = $this->scan($db, $definition, $cursor, $limit);
            $this->reindex($definition, $ids, $batch);

            $state = $this->names()->quoted($this->names()->state());

            if (count($ids) < $limit) {
                $done = $db->affectingStatement(
                    sprintf('UPDATE %s SET "rebuild_cursor" = NULL, "rebuild_started_at" = NULL, "ready_at" = COALESCE("ready_at", clock_timestamp()) WHERE "subject_type" = ? AND "rebuild_cursor" = ?', $state),
                    [$type, $cursor],
                );

                if ($done === 1) {
                    $batch->rebuiltTypes[] = $type;
                }

                return true;
            }

            $db->affectingStatement(
                sprintf('UPDATE %s SET "rebuild_cursor" = ? WHERE "subject_type" = ? AND "rebuild_cursor" = ?', $state),
                [$ids[array_key_last($ids)], $type, $cursor],
            );

            return true;
        });

        foreach ($batch->rebuiltTypes as $type) {
            $this->state->forget($type);
        }

        $this->announce($batch);
        $total->merge($batch);

        return $outcome;
    }

    /**
     * A type whose rebuild is pending and that this release may rebuild: the index has never been
     * complete, or this release's definition is the one the install recorded. A release that merely
     * differs (the previous one, during a blue/green deploy) leaves the rebuild to the owner.
     *
     * @param  array<string, CompiledDefinition>  $definitions
     */
    private function nextRebuild(array $definitions): ?CompiledDefinition
    {
        foreach ($this->state->pendingRebuilds() as $row) {
            $definition = $definitions[$row['subject_type']] ?? null;

            if ($definition === null) {
                continue;
            }

            if ($row['ready_at'] === null || $row['definition_fingerprint'] === $this->fingerprint($definition)) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Removes entries (and their keys) whose root no longer exists or is soft deleted: what a
     * TRUNCATE leaves behind, since it logs nothing per row. Bounded per call.
     */
    private function sweep(Connection $db, CompiledDefinition $definition, int $limit, DrainStats $batch): int
    {
        $model = new ($definition->modelClass);
        $soft = Sql::softDeleteColumn($model);
        $entries = $this->names()->quoted($this->names()->entries());
        $keys = $this->names()->quoted($this->names()->keys());

        $row = $db->selectOne(
            sprintf(
                'WITH "gone" AS (SELECT e."subject_id" FROM %1$s AS e WHERE e."subject_type" = ? AND NOT EXISTS (SELECT 1 FROM %2$s AS r WHERE r.%3$s::text = e."subject_id"%4$s) LIMIT %5$d), "deleted_keys" AS (DELETE FROM %6$s AS k USING "gone" AS g WHERE k."subject_type" = ? AND k."subject_id" = g."subject_id" RETURNING 1), "deleted_entries" AS (DELETE FROM %1$s AS e USING "gone" AS g WHERE e."subject_type" = ? AND e."subject_id" = g."subject_id" RETURNING 1) SELECT (SELECT count(*) FROM "deleted_entries") AS "entries", (SELECT count(*) FROM "deleted_keys") AS "keys"',
                $entries,
                Sql::table($model),
                Sql::ident($model->getKeyName()),
                $soft === null ? '' : sprintf(' AND r.%s IS NULL', Sql::ident($soft)),
                $limit,
                $keys,
            ),
            [$definition->morphAlias, $definition->morphAlias, $definition->morphAlias],
        );

        if (! $row instanceof stdClass) {
            return 0;
        }

        $removed = (int) $row->entries;
        $removedKeys = (int) $row->keys;

        if ($removed + $removedKeys > 0) {
            $batch->entriesDeleted += $removed;
            $batch->keysDeleted += $removedKeys;
            $batch->touch($definition->morphAlias);
        }

        return $removed;
    }

    /**
     * The next page of root ids after the cursor, in the key's own order.
     *
     * @return list<string>
     */
    private function scan(Connection $db, CompiledDefinition $definition, string $cursor, int $limit): array
    {
        $model = new ($definition->modelClass);
        $soft = Sql::softDeleteColumn($model);
        $key = Sql::ident($model->getKeyName());
        $conditions = [];
        $bindings = [];

        if ($cursor !== '') {
            $conditions[] = sprintf('"r".%s > ?::%s', $key, $definition->keySqlType ?? 'text');
            $bindings[] = $cursor;
        }

        if ($soft !== null) {
            $conditions[] = sprintf('"r".%s IS NULL', Sql::ident($soft));
        }

        $rows = $db->select(
            sprintf(
                'SELECT "r".%1$s::text AS "id" FROM %2$s AS "r"%3$s ORDER BY "r".%1$s LIMIT %4$d',
                $key,
                Sql::table($model),
                $conditions === [] ? '' : ' WHERE '.implode(' AND ', $conditions),
                $limit,
            ),
            $bindings,
        );

        return array_values(array_map(static fn (stdClass $row): string => (string) $row->id, $rows));
    }

    /**
     * Recomputes the projection of the given roots. A failing batch is retried record by record;
     * a record that still fails goes to the failures table and the rest carry on.
     *
     * @param  list<string>  $ids
     */
    private function reindex(CompiledDefinition $definition, array $ids, DrainStats $batch): void
    {
        if ($ids === []) {
            return;
        }

        $db = $this->db();

        try {
            $batch->merge($db->transaction(fn (): DrainStats => $this->reindexAll($definition, $ids)));

            return;
        } catch (Throwable $failure) {
            if (PoisonRecords::isTransient($failure)) {
                throw $failure;
            }
        }

        foreach ($ids as $id) {
            try {
                $batch->merge($db->transaction(fn (): DrainStats => $this->reindexAll($definition, [$id])));
            } catch (Throwable $failure) {
                if (PoisonRecords::isTransient($failure)) {
                    throw $failure;
                }

                $this->poison()->record($db, $definition->morphAlias, $id, $failure);
                $batch->poisoned++;
            }
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function reindexAll(CompiledDefinition $definition, array $ids): DrainStats
    {
        $db = $this->db();
        $attempt = new DrainStats;
        $attempt->roots = count($ids);

        $desired = $this->contributions()->for($definition, $ids, $db);
        new DiffWriter($this->names())->write($db, $definition->morphAlias, $ids, $desired, $attempt);
        $this->poison()->clear($db, $definition->morphAlias, $ids);

        return $attempt;
    }

    /**
     * Moves the types whose share of the change log exceeds the threshold to a rebuild and drops
     * those log rows: replaying a million rows one by one is slower than starting over.
     *
     * @param  array<string, CompiledDefinition>  $definitions
     */
    private function switchOversizedTypes(array $definitions, DrainStats $stats): void
    {
        $threshold = (int) config('model-search.drain.log_rebuild_threshold', 1000000);

        if ($threshold < 1) {
            return;
        }

        $db = $this->db();
        $changes = $this->names()->quoted($this->names()->changes());

        if ((int) ($db->selectOne(sprintf('SELECT count(*) AS "n" FROM (SELECT 1 FROM %s LIMIT %d) AS "s"', $changes, $threshold + 1))->n ?? 0) <= $threshold) {
            return;
        }

        $batch = new DrainStats;

        $db->transaction(function () use ($db, $definitions, $changes, $threshold, $batch): void {
            if (! $this->lock($db)) {
                return;
            }

            $counts = [];

            foreach ($db->select(sprintf('SELECT "node_type", count(*) AS "n" FROM %s GROUP BY "node_type"', $changes)) as $row) {
                $counts[(string) $row->node_type] = (int) $row->n;
            }

            $oversized = [];
            $users = [];

            foreach ($definitions as $alias => $definition) {
                $types = $this->resolver()->nodeTypesOf($definition);
                $oversized[$alias] = array_sum(array_map(static fn (string $type): int => $counts[$type] ?? 0, $types)) > $threshold;

                foreach ($types as $type) {
                    $users[$type][] = $alias;
                }
            }

            $candidates = array_keys(array_filter($oversized));

            if ($candidates === []) {
                return;
            }

            $rows = $db->select(
                sprintf('UPDATE %s SET "rebuild_cursor" = \'\', "rebuild_started_at" = clock_timestamp() WHERE "subject_type" = ANY(?::text[]) RETURNING "subject_type"', $this->names()->quoted($this->names()->state())),
                ['{'.implode(',', array_map(static fn (string $type): string => '"'.addcslashes($type, '"\\').'"', $candidates)).'}'],
            );

            $switched = array_map(static fn (stdClass $row): string => (string) $row->subject_type, $rows);

            if ($switched === []) {
                return;
            }

            $discard = [];

            foreach ($users as $type => $aliases) {
                if (array_diff($aliases, $switched) === []) {
                    $discard[] = (string) $type;
                }
            }

            if ($discard !== []) {
                $batch->logRowsDiscarded += $db->affectingStatement(
                    sprintf('DELETE FROM %s WHERE "node_type" = ANY(?::text[])', $changes),
                    ['{'.implode(',', array_map(static fn (string $type): string => '"'.addcslashes($type, '"\\').'"', $discard)).'}'],
                );
            }

            $batch->switchedTypes = array_values($switched);
        });

        $stats->merge($batch);
    }

    private function watchLag(DrainStats $stats): void
    {
        $stats->lagSeconds = $this->lag();
        $alarm = (int) config('model-search.lag_alarm_seconds', 300);

        if ($stats->lagSeconds !== null && $alarm > 0 && $stats->lagSeconds >= $alarm) {
            Event::dispatch(new SearchIndexLagging($stats->lagSeconds, $alarm));
        }
    }

    private function announce(DrainStats $batch): void
    {
        foreach ($batch->changedTypes as $type) {
            Event::dispatch(new SearchIndexChanged($type));
        }
    }

    /**
     * Collapses the rows of a batch to unique nodes: at most `drain.nodes` of them. The rows read
     * are those up to the point where one more unique node would exceed the limit.
     *
     * @param  array<int, stdClass>  $rows
     * @return array{list<int>, list<array{string, string}>} The ids of the rows consumed, and the unique nodes.
     */
    private function dedupe(array $rows): array
    {
        $limit = max(1, (int) config('model-search.drain.nodes', 1000));
        $consumed = [];
        $seen = [];
        $nodes = [];

        foreach ($rows as $row) {
            $identity = $row->node_type."\0".$row->node_id;

            if (! isset($seen[$identity])) {
                if (count($seen) >= $limit) {
                    break;
                }

                $seen[$identity] = true;
                $nodes[] = [(string) $row->node_type, (string) $row->node_id];
            }

            $consumed[] = (int) $row->id;
        }

        return [$consumed, $nodes];
    }

    private function lock(Connection $db): bool
    {
        return (bool) ($db->selectOne('SELECT pg_try_advisory_xact_lock(?) AS "locked"', [$this->lockKey()])->locked ?? false);
    }

    /**
     * @return array<string, CompiledDefinition> Keyed by morph alias.
     */
    private function definitions(): array
    {
        $definitions = [];

        foreach ($this->registry->all() as $definition) {
            $definitions[$definition->morphAlias] = $definition;
        }

        return $definitions;
    }

    private function fingerprint(CompiledDefinition $definition): string
    {
        return $this->fingerprints[$definition->morphAlias] ??= Fingerprint::of($definition);
    }

    private function contributions(): Contributions
    {
        return $this->contributions ??= new Contributions;
    }

    private function resolver(): RootResolver
    {
        return $this->resolver ??= new RootResolver($this->names());
    }

    private function poison(): PoisonRecords
    {
        return new PoisonRecords($this->names());
    }

    private function names(): TableNames
    {
        return TableNames::forConnection($this->connection);
    }

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }
}
