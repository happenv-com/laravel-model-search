<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Indexing;

use Happenv\ModelSearch\Compilation\Fingerprint;
use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Events\SearchIndexChanged;
use Happenv\ModelSearch\Events\SearchIndexLagging;
use Happenv\ModelSearch\Exceptions\DrainInsideTransaction;
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
 *
 * A release applies changes and rebuilds only for the types it owns (see {@see IndexState}). It may,
 * for types it does not own, only flag a rebuild or enqueue a row: the oversized-log switch, the
 * handoff of aged log rows and the retry of due failures. The owner does the work.
 *
 * Known cost of the age-based handoff: two live releases that read the same node type through
 * different definitions for longer than `drain.foreign_grace_seconds` flag full rebuilds of each
 * other's types. Nothing is lost (a rebuild recomputes everything), but the work is wasted; keep
 * the window of two live releases shorter than the grace period.
 */
final class SearchIndexer
{
    private ?Contributions $contributions = null;

    private ?RootResolver $resolver = null;

    /** @var array<string, string> */
    private array $fingerprints = [];

    /** Highest log row id this drain has read: kept rows lie below it and are not read again. */
    private int $watermark = 0;

    /** @var list<int> Rows left in the log by the last batch because their node was deferred; read again first. */
    private array $deferredIds = [];

    /** @var array<string, true> Types whose rebuild step failed in this drain. */
    private array $skippedRebuilds = [];

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

        // A host that wraps everything in one transaction (a test harness) raises the allowed level.
        if ($this->db()->transactionLevel() > (int) config('model-search.drain.allowed_transaction_level', 0)) {
            throw new DrainInsideTransaction('A drain must not run inside an open transaction: its advisory lock and its events would leak into the caller\'s transaction.');
        }

        $this->watermark = 0;
        $this->deferredIds = [];
        $this->skippedRebuilds = [];

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

        $ownership = $this->ownership($definitions);
        $owned = array_filter($definitions, static fn (CompiledDefinition $definition): bool => $ownership[$definition->morphAlias]);
        $readable = [];
        $foreign = [];
        $known = [];

        foreach ($definitions as $definition) {
            foreach ($this->resolver()->nodeTypesOf($definition) as $type) {
                $known[$type] = true;

                if ($ownership[$definition->morphAlias]) {
                    $readable[$type] = true;
                } else {
                    $foreign[$type][] = $definition->morphAlias;
                }
            }
        }

        $outcome = $db->transaction(function () use ($db, $owned, $changes, $batch, $readable, $foreign, $known): ?bool {
            if (! $this->lock($db)) {
                return null;
            }

            // A row an unowned definition reads is kept for its owner while it is young; once it is old the
            // owner is assumed to be away, so the rebuild of the unowned types is flagged and the row goes.
            // Rows of types nobody knows wait out their own grace period, so they never stall the batch.
            $foreignGrace = max(0, (int) config('model-search.drain.foreign_grace_seconds', 3600));
            $rows = $db->select(
                sprintf(
                    'SELECT "id", "node_type", "node_id", ("changed_at" < clock_timestamp() - make_interval(secs => ?::double precision)) AS "aged", "changed_at"::text AS "changed" FROM %1$s WHERE ("node_type" = ANY(?::text[]) OR ("node_type" = ANY(?::text[]) AND "changed_at" < clock_timestamp() - make_interval(secs => ?::double precision)) OR ("node_type" <> ALL(?::text[]) AND "changed_at" < clock_timestamp() - make_interval(secs => ?::double precision))) AND ("id" > ? OR "id" = ANY(?::bigint[])) ORDER BY "id" LIMIT %2$d',
                    $changes,
                    max(1, (int) config('model-search.drain.rows', 5000)),
                ),
                [
                    $foreignGrace,
                    Sql::arrayLiteral(array_map(strval(...), array_keys($readable))),
                    Sql::arrayLiteral(array_map(strval(...), array_keys($foreign))),
                    $foreignGrace,
                    Sql::arrayLiteral(array_map(strval(...), array_keys($known))),
                    max(0, (int) config('model-search.drain.unknown_grace_seconds', 3600)),
                    $this->watermark,
                    Sql::arrayLiteral($this->deferredIds),
                ],
            );

            if ($rows === []) {
                return false;
            }

            [$read, $nodes] = $this->dedupe($rows);
            $readSet = array_flip($read);
            $this->watermark = max($this->watermark, ...$read);
            $this->deferredIds = [];
            $applicable = array_values(array_filter($nodes, static fn (array $node): bool => isset($readable[$node[0]]) || ! isset($foreign[$node[0]])));

            $batch->logBatches++;
            $batch->logRowsRead = count($read);
            $batch->nodes = count($nodes);

            $cap = max((int) config('model-search.drain.rows', 5000), (int) config('model-search.drain.nodes', 1000), 1);
            $resolved = $this->resolver()->resolve($db, $owned, $applicable, $batch, $cap);
            $deferred = [];

            foreach ($resolved['deferred'] as [$type, $id]) {
                $deferred[$type."\0".$id] = true;
            }

            foreach ($resolved['roots'] as $alias => $rootIds) {
                if (count($rootIds) > $cap) {
                    $batch->spilledRoots += $this->resolver()->spill($db, $alias, array_slice($rootIds, $cap));
                    $rootIds = array_slice($rootIds, 0, $cap);
                }

                $this->reindex($owned[$alias], $rootIds, $batch);
            }

            // Deferred nodes keep their rows (they are read again first), kept rows keep theirs; the rest go.
            $consumed = [];
            $flag = [];

            foreach ($rows as $row) {
                $id = (int) $row->id;
                $type = (string) $row->node_type;

                if (! isset($readSet[$id])) {
                    continue;
                }

                if (isset($deferred[$type."\0".$row->node_id])) {
                    $this->deferredIds[] = $id;

                    continue;
                }

                if (isset($foreign[$type]) && ! $row->aged) {
                    continue;
                }

                if (isset($foreign[$type])) {
                    $batch->handedOffRows++;

                    foreach ($foreign[$type] as $alias) {
                        $flag[$alias] = max($flag[$alias] ?? '', (string) $row->changed);
                    }
                }

                $consumed[] = $id;
            }

            if ($flag !== []) {
                $batch->flaggedTypes = array_map(strval(...), array_keys($this->flagRebuilds($db, $flag)));
            }

            if ($consumed !== []) {
                $db->affectingStatement(sprintf('DELETE FROM %s WHERE "id" = ANY(?::bigint[])', $changes), [Sql::arrayLiteral($consumed)]);
            }

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

        try {
            $outcome = $this->rebuildStep($db, $definition, $batch);
        } catch (Throwable $failure) {
            if (PoisonRecords::isTransient($failure)) {
                throw $failure;
            }

            // One type's broken rebuild must not stop the log or the other types: report it and leave it for the next drain.
            report($failure);
            $this->skippedRebuilds[$definition->morphAlias] = true;

            return true;
        }

        foreach ($batch->rebuiltTypes as $type) {
            $this->state->forget($type);
        }

        $this->announce($batch);
        $total->merge($batch);

        return $outcome;
    }

    private function rebuildStep(Connection $db, CompiledDefinition $definition, DrainStats $batch): ?bool
    {
        return $db->transaction(function () use ($db, $definition, $batch): ?bool {
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

            $startedAt = $row['rebuild_started_at'];

            if ($cursor === '' && ! $this->sweep($db, $definition, $row['sweep_cursor'], $startedAt, $limit, $batch)) {
                return true;
            }

            $ids = $this->scan($db, $definition, $cursor, $limit);
            $this->reindex($definition, $ids, $batch);

            $state = $this->names()->quoted($this->names()->state());

            if (count($ids) < $limit) {
                $done = $db->affectingStatement(
                    sprintf('UPDATE %s SET "rebuild_cursor" = NULL, "rebuild_started_at" = NULL, "sweep_cursor" = NULL, "ready_at" = COALESCE("ready_at", clock_timestamp()) WHERE "subject_type" = ? AND "rebuild_cursor" = ? AND "rebuild_started_at" IS NOT DISTINCT FROM ?::timestamptz', $state),
                    [$type, $cursor, $startedAt],
                );

                if ($done === 1) {
                    $batch->rebuiltTypes[] = $type;
                }

                return true;
            }

            $db->affectingStatement(
                sprintf('UPDATE %s SET "rebuild_cursor" = ? WHERE "subject_type" = ? AND "rebuild_cursor" = ? AND "rebuild_started_at" IS NOT DISTINCT FROM ?::timestamptz', $state),
                [$ids[array_key_last($ids)], $type, $cursor, $startedAt],
            );

            return true;
        });
    }

    /**
     * A type whose rebuild is pending and that this release owns: its definition is the one the
     * install recorded. A release that merely differs (the previous one, during a blue/green deploy)
     * leaves the rebuild to the owner, also while the index has never been complete.
     *
     * @param  array<string, CompiledDefinition>  $definitions
     */
    private function nextRebuild(array $definitions): ?CompiledDefinition
    {
        foreach ($this->state->pendingRebuilds() as $row) {
            $definition = $definitions[$row['subject_type']] ?? null;

            if ($definition === null || isset($this->skippedRebuilds[$row['subject_type']])) {
                continue;
            }

            if ($row['definition_fingerprint'] === $this->fingerprint($definition)) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * One bounded step of the sweep that opens a rebuild: walks the type's entries by key from the
     * sweep cursor, `drain.rows` at a time, and removes the entries and key rows of roots that no
     * longer exist or are soft deleted (what a TRUNCATE leaves behind, since it logs nothing per
     * row). The cursor is advanced by compare-and-swap on the cursor AND the moment the rebuild started,
     * so a restart of the rebuild in the meantime (every restart stamps a new moment) wins. Key rows without an entry are not looked for here: entry and keys are written in one
     * transaction, and the doctor checks for strays.
     *
     * @return bool True when the whole type was walked.
     */
    private function sweep(Connection $db, CompiledDefinition $definition, ?string $cursor, ?string $startedAt, int $limit, DrainStats $batch): bool
    {
        $model = new ($definition->modelClass);
        $soft = Sql::softDeleteColumn($model);
        $entries = $this->names()->quoted($this->names()->entries());
        $keys = $this->names()->quoted($this->names()->keys());
        $type = $definition->morphAlias;

        $page = array_values(array_map(
            static fn (stdClass $row): string => (string) $row->subject_id,
            $db->select(
                sprintf('SELECT "subject_id" FROM %s WHERE "subject_type" = ?%s ORDER BY "subject_id" LIMIT %d', $entries, $cursor === null ? '' : ' AND "subject_id" > ?', $limit),
                $cursor === null ? [$type] : [$type, $cursor],
            ),
        ));

        if ($page !== []) {
            $gone = array_values(array_map(
                static fn (stdClass $row): string => (string) $row->id,
                $db->select(
                    sprintf(
                        'SELECT u."id" FROM unnest(?::text[]) AS u("id") WHERE NOT EXISTS (SELECT 1 FROM %s AS r WHERE r.%s = u."id"::%s%s)',
                        Sql::table($model),
                        Sql::ident($model->getKeyName()),
                        $definition->resolvedKeySqlType(),
                        $soft === null ? '' : sprintf(' AND r.%s IS NULL', Sql::ident($soft)),
                    ),
                    [Sql::arrayLiteral($page)],
                ),
            ));

            if ($gone !== []) {
                $batch->keysDeleted += $db->affectingStatement(sprintf('DELETE FROM %s WHERE "subject_type" = ? AND "subject_id" = ANY(?::text[])', $keys), [$type, Sql::arrayLiteral($gone)]);
                $batch->entriesDeleted += $db->affectingStatement(sprintf('DELETE FROM %s WHERE "subject_type" = ? AND "subject_id" = ANY(?::text[])', $entries), [$type, Sql::arrayLiteral($gone)]);
                $batch->touch($type);
            }
        }

        $finished = count($page) < $limit;
        $next = $finished ? null : $page[count($page) - 1];
        $moved = $db->affectingStatement(
            sprintf('UPDATE %s SET "sweep_cursor" = ? WHERE "subject_type" = ? AND "rebuild_cursor" = \'\' AND "rebuild_started_at" IS NOT DISTINCT FROM ?::timestamptz AND "sweep_cursor" IS NOT DISTINCT FROM ?', $this->names()->quoted($this->names()->state())),
            [$next, $type, $startedAt, $cursor],
        );

        return $moved === 1 && $finished;
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
            $conditions[] = sprintf('"r".%s > ?::%s', $key, $definition->resolvedKeySqlType());
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
     * Recomputes the projection of the given roots. A failing batch is split in halves until the
     * failing record stands alone; that record goes to the failures table and the rest carry on.
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

            if (count($ids) === 1) {
                $this->poison()->record($db, $definition->morphAlias, $ids[0], $failure);
                $batch->poisoned++;

                return;
            }
        }

        // Bisect: the halves that succeed are written, the poison record is cornered in about log2(n) steps.
        foreach (array_chunk($ids, max(1, intdiv(count($ids) + 1, 2))) as $half) {
            $this->reindex($definition, $half, $batch);
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
                sprintf('UPDATE %s SET "rebuild_cursor" = \'\', "rebuild_started_at" = clock_timestamp(), "sweep_cursor" = NULL WHERE "subject_type" = ANY(?::text[]) RETURNING "subject_type"', $this->names()->quoted($this->names()->state())),
                [Sql::arrayLiteral($candidates)],
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
                    [Sql::arrayLiteral($discard)],
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

    /**
     * Asks the owner to rebuild types this release does not own. A flag only: it never rebuilds.
     * A type with no rebuild pending is flagged. A rebuild that is already running is restarted
     * only when it started before the handed-off change and has left the start, since it may
     * have passed the root already; a rebuild at the start will read the change anyway.
     *
     * @param  array<string, string>  $changedAt  Per type: the latest `changed_at` of the rows handed over.
     * @return array<string, true> The types that were flagged.
     */
    private function flagRebuilds(Connection $db, array $changedAt): array
    {
        $rows = $db->select(
            sprintf(
                'UPDATE %s AS s SET "rebuild_cursor" = \'\', "rebuild_started_at" = clock_timestamp(), "sweep_cursor" = NULL FROM unnest(?::text[], ?::timestamptz[]) AS u("alias", "changed") WHERE s."subject_type" = u."alias" AND (s."rebuild_cursor" IS NULL OR (s."rebuild_cursor" <> \'\' AND (s."rebuild_started_at" IS NULL OR s."rebuild_started_at" < u."changed"))) RETURNING s."subject_type"',
                $this->names()->quoted($this->names()->state()),
            ),
            [Sql::arrayLiteral(array_map(strval(...), array_keys($changedAt))), Sql::arrayLiteral(array_values($changedAt))],
        );

        $flagged = [];

        foreach ($rows as $row) {
            $flagged[(string) $row->subject_type] = true;
        }

        return $flagged;
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

    /**
     * Whether this release owns each type: it has no state row yet, or the stored fingerprint is
     * the fingerprint of this release's definition.
     *
     * @param  array<string, CompiledDefinition>  $definitions
     * @return array<string, bool>
     */
    private function ownership(array $definitions): array
    {
        $stored = $this->state->fingerprints();
        $owned = [];

        foreach ($definitions as $alias => $definition) {
            $owned[$alias] = ! isset($stored[$alias]) || $stored[$alias] === $this->fingerprint($definition);
        }

        return $owned;
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
