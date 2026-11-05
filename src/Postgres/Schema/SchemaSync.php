<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Compilation\WatchedTable;
use Happenv\ModelSearch\Exceptions\MissingExtension;
use Happenv\ModelSearch\Exceptions\NotTableOwner;
use Happenv\ModelSearch\Exceptions\SchemaNotInstalled;
use Happenv\ModelSearch\Exceptions\SchemaSyncIncomplete;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDOException;
use stdClass;

/**
 * Keeps the triggers and indexes derived from the registered definitions in step with the database.
 *
 * Triggers and the indexes of the match modes are an artefact of the definitions, like a config
 * cache, so they are generated and installed here instead of living in a frozen migration:
 * - {@see plan()}: the desired objects;
 * - {@see diff()}: what differs from the database (missing, changed, disabled, obsolete);
 * - {@see apply()}: executes the differences. Idempotent; an object is recognised as ours by the
 *   `model-search:<sha256>` comment, and as drifted when that hash is not the planned one.
 *
 * Triggers are replaced one table at a time, each in its own transaction with a short
 * `lock_timeout`: `CREATE OR REPLACE TRIGGER` takes SHARE ROW EXCLUSIVE and must not queue behind
 * a long writer while blocking the ones behind it. A lock timeout is retried (jittered backoff);
 * a table that stays locked is left for later while the rest, indexes included, is installed, and
 * the run ends with one {@see SchemaSyncIncomplete}. Indexes are built with CONCURRENTLY, outside
 * any transaction; a failed build leaves an invalid index, which the next run drops and rebuilds.
 * Concurrent runs are serialised by a session advisory lock; the second one does nothing.
 *
 * Nothing is dropped by {@see apply()} (and so never on a deploy: the previous release may still
 * need an object the new plan no longer has). What is no longer planned is reported by
 * {@see obsolete()} and removed by `apply(prune: true)`, an explicit step for after the old release
 * is gone.
 *
 * Privileges: the role running the sync must own the watched tables and the package tables (or be
 * a member of their owner). The trigger functions are SECURITY INVOKER, so the roles that write to
 * watched tables need INSERT on the changes table, and UPDATE on the state table to TRUNCATE one.
 */
final readonly class SchemaSync
{
    public function __construct(
        private ModelSearchRegistry $registry,
        private ?string $connection = null,
        private string $lockTimeout = '2s',
        private int $retries = 3,
        private int $backoffMs = 100,
    ) {}

    /**
     * Whether the package's own tables exist.
     */
    public function installed(): bool
    {
        $names = $this->names();

        return array_all([$names->entries(), $names->keys(), $names->changes(), $names->state()], fn (string $table): bool => $this->tableExists(Sql::ident($table)));
    }

    public function plan(): SchemaPlan
    {
        $names = $this->names();
        $schema = $this->currentSchema();
        $definitions = $this->registry->all();
        $connectionName = $this->db()->getName();

        /** @var array<string, array{table: string, quoted: string, entries: array<string, WatchedTable>, roots: array<string, true>}> $tables */
        $tables = [];

        foreach ($definitions as $definition) {
            foreach ($definition->watchedTables() as $watched) {
                if ($watched->connection !== $connectionName) {
                    continue;
                }

                $tables[$watched->quotedTable] ??= ['table' => $watched->table, 'quoted' => $watched->quotedTable, 'entries' => [], 'roots' => []];
                $key = $watched->identity();
                $existing = $tables[$watched->quotedTable]['entries'][$key] ?? null;
                $tables[$watched->quotedTable]['entries'][$key] = $existing?->withColumns([...$existing->columns, ...$watched->columns]) ?? $watched;
                $tables[$watched->quotedTable]['roots'][$definition->morphAlias] = true;
            }
        }

        ksort($tables);
        $sql = new TriggerSql($schema, $names);
        $triggers = [];

        foreach ($tables as $table) {
            $triggers[] = $sql->render(new TableTriggers($table['table'], $table['quoted'], array_values($table['entries']), array_keys($table['roots'])));
        }

        return new SchemaPlan($schema, $triggers, IndexPlan::for($definitions, $names));
    }

    /**
     * What is missing or differs from the plan (create/replace, plus invalid index leftovers).
     *
     * @return list<SchemaChange>
     */
    public function diff(): array
    {
        $this->assertReady();
        $plan = $this->plan();

        return $this->withLockTimeout(fn (): array => [...$this->triggerChanges($plan), ...$this->indexChanges($plan)]);
    }

    /**
     * What exists but is no longer planned. Reported, never applied, unless pruning.
     *
     * @return list<SchemaChange>
     */
    public function obsolete(): array
    {
        $this->assertReady();
        $plan = $this->plan();

        return $this->withLockTimeout(fn (): array => [...$this->obsoleteIndexes($plan), ...$this->obsoleteTriggers($plan)]);
    }

    public function apply(bool $prune = false): SyncOutcome
    {
        $readOnly = $this->readOnlyReason();

        if ($readOnly !== null) {
            return new SyncOutcome([], $readOnly);
        }

        $db = $this->db();
        $key = 'model-search-sync:'.$this->names()->prefix();

        if (! $db->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS locked', [$key])?->locked) {
            return new SyncOutcome([], 'another sync is running');
        }

        try {
            $changes = $this->diff();

            if ($prune) {
                $changes = [...$changes, ...$this->obsolete()];
            }

            $this->assertOwnership($changes);

            return $this->run($changes);
        } finally {
            $db->selectOne('SELECT pg_advisory_unlock(hashtext(?))', [$key]);
        }
    }

    /**
     * Removes every trigger function the package installed, and with them the triggers (used when
     * the package tables are dropped: a trigger must not outlive the table it writes to).
     */
    public function uninstall(): void
    {
        $db = $this->db();

        foreach ($this->markedRoutines() as $routine) {
            $db->transaction(function () use ($db, $routine): void {
                $db->getPdo()->exec(sprintf('DROP FUNCTION IF EXISTS %s CASCADE', $routine['routine']));
            });
        }
    }

    /**
     * @param  list<SchemaChange>  $changes
     */
    private function run(array $changes): SyncOutcome
    {
        $left = [];

        foreach ($changes as $change) {
            if (! $this->executeWithRetry($change)) {
                $left[] = $change->subject;
            }
        }

        if ($left !== []) {
            throw SchemaSyncIncomplete::leaving($left);
        }

        return new SyncOutcome($changes);
    }

    /**
     * @return bool False when the lock could not be taken in time, after the retries.
     */
    private function executeWithRetry(SchemaChange $change): bool
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $this->execute($change);

                return true;
            } catch (QueryException|PDOException $exception) {
                if (($exception->errorInfo[0] ?? null) !== '55P03') {
                    throw $exception;
                }

                if ($attempt >= $this->retries) {
                    return false;
                }

                usleep(random_int(1, 2) * $this->backoffMs * ($attempt + 1) * 1000);
            }
        }
    }

    private function execute(SchemaChange $change): void
    {
        $db = $this->db();

        if (! $change->transactional) {
            if ($db->transactionLevel() > 0) {
                throw new LogicException('CREATE INDEX CONCURRENTLY cannot run inside a transaction; run the sync outside of one.');
            }

            $previous = $db->selectOne("SELECT current_setting('lock_timeout') AS value");
            $db->selectOne("SELECT set_config('lock_timeout', ?, false)", [$this->lockTimeout]);

            try {
                foreach ($change->statements as $statement) {
                    $db->getPdo()->exec($statement);
                }
            } finally {
                $db->selectOne("SELECT set_config('lock_timeout', ?, false)", [$previous === null ? '0' : (string) $previous->value]);
            }

            return;
        }

        $db->transaction(function () use ($db, $change): void {
            $db->selectOne("SELECT set_config('lock_timeout', ?, true)", [$this->lockTimeout]);

            foreach ($change->statements as $statement) {
                $db->getPdo()->exec($statement);
            }

            foreach ($change->stamps as $stamp) {
                $this->stamp($stamp);
            }
        });
    }

    /**
     * Writes the marker with the definition PostgreSQL reads back for the object just created.
     *
     * @param  array{kind: 'function'|'trigger', name: string, on: string, sql: string}  $stamp
     */
    private function stamp(array $stamp): void
    {
        $db = $this->db();

        if ($stamp['kind'] === 'function') {
            $row = $db->selectOne('SELECT md5(pg_get_functiondef(to_regprocedure(?))) AS md5', [$stamp['name'].'()']);
            $statement = sprintf('COMMENT ON FUNCTION %s() IS %s', $stamp['name'], TriggerSql::literal(Marker::of($stamp['sql'], (string) $row?->md5)));
        } else {
            $row = $db->selectOne('SELECT md5(pg_get_triggerdef(t.oid)) AS md5 FROM pg_trigger t WHERE t.tgrelid = to_regclass(?) AND t.tgname = ?', [$stamp['on'], $stamp['name']]);
            $statement = sprintf('COMMENT ON TRIGGER %s ON %s IS %s', Sql::ident($stamp['name']), $stamp['on'], TriggerSql::literal(Marker::of($stamp['sql'], (string) $row?->md5)));
        }

        $db->getPdo()->exec($statement);
    }

    /**
     * @return list<SchemaChange>
     */
    private function triggerChanges(SchemaPlan $plan): array
    {
        $changes = [];

        foreach ($plan->triggers as $rendered) {
            if (! $this->tableExists($rendered->quotedTable)) {
                continue;
            }

            try {
                $inspected = $this->inspectTriggers($rendered);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) !== '55P03') {
                    throw $exception;
                }

                // Cannot even read the table's triggers: leave it to the run, which retries the lock.
                $changes[] = new SchemaChange('triggers', 'replace', $rendered->table, 'the table is locked and could not be inspected', $rendered->statements(), true, [$rendered->quotedTable], [], $rendered->stamps());

                continue;
            }

            [$reasons, $exists] = $inspected;

            if ($reasons === []) {
                continue;
            }

            $changes[] = new SchemaChange(
                'triggers',
                $exists ? 'replace' : 'create',
                $rendered->table,
                implode('; ', $reasons),
                $rendered->statements(),
                true,
                [$rendered->quotedTable],
                [],
                $rendered->stamps(),
            );
        }

        return $changes;
    }

    /**
     * What is wrong with one table's trigger function and triggers.
     *
     * @return array{list<string>, bool} The reasons, and whether the function exists.
     */
    private function inspectTriggers(RenderedTriggers $rendered): array
    {
        $db = $this->db();

        $reasons = [];
        $function = $db->selectOne(
            "SELECT md5(pg_get_functiondef(p.oid)) AS definition, d.description AS comment FROM pg_proc p LEFT JOIN pg_description d ON d.objoid = p.oid AND d.classoid = 'pg_proc'::regclass WHERE p.oid = to_regprocedure(?)",
            [$rendered->qualifiedFunction.'()'],
        );

        if ($function === null) {
            $reasons[] = 'function is missing';
        } elseif (! $this->matches($function->comment, $rendered->functionSql, (string) $function->definition)) {
            $reasons[] = 'function differs from the definitions';
        }

        $live = $this->liveTriggers($rendered->quotedTable);

        foreach ($rendered->triggers as $name => $createSql) {
            $row = $live[$name] ?? null;

            if ($row === null) {
                $reasons[] = sprintf('trigger %s is missing', $name);
            } elseif (! $this->matches($row->comment, $createSql, (string) $row->definition)) {
                $reasons[] = sprintf('trigger %s differs from the definitions', $name);
            } elseif ($row->enabled !== 'O') {
                $reasons[] = sprintf('trigger %s is disabled', $name);
            }
        }

        return [$reasons, $function !== null];
    }

    /**
     * Whether the comment says the object was made from this statement and still has the
     * definition it had right after.
     */
    private function matches(mixed $comment, string $sql, string $liveDefinition): bool
    {
        $marker = Marker::parse($comment);

        return $marker !== null
            && $marker['plan'] === hash('sha256', $sql)
            && $marker['definition'] === $liveDefinition;
    }

    /**
     * @return array<string, stdClass>
     */
    private function liveTriggers(string $quotedTable): array
    {
        $live = [];

        foreach ($this->db()->select(
            "SELECT t.tgname AS name, t.tgenabled AS enabled, md5(pg_get_triggerdef(t.oid)) AS definition, d.description AS comment FROM pg_trigger t LEFT JOIN pg_description d ON d.objoid = t.oid AND d.classoid = 'pg_trigger'::regclass WHERE t.tgrelid = to_regclass(?) AND NOT t.tgisinternal",
            [$quotedTable],
        ) as $row) {
            $live[(string) $row->name] = $row;
        }

        return $live;
    }

    /**
     * Triggers of the package that nothing plans: a table no definition watches any more (its
     * function goes, and the triggers with it), or a shape a watched table no longer needs.
     *
     * @return list<SchemaChange>
     */
    private function obsoleteTriggers(SchemaPlan $plan): array
    {
        $sql = new TriggerSql($plan->schema, $this->names());
        $wanted = [];
        $changes = [];

        foreach ($plan->triggers as $rendered) {
            $wanted[$rendered->functionName] = true;

            if (! $this->tableExists($rendered->quotedTable)) {
                continue;
            }

            try {
                $live = $this->liveTriggers($rendered->quotedTable);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) !== '55P03') {
                    throw $exception;
                }

                continue;
            }

            foreach ($sql->triggerNames($rendered->table) as $name) {
                if (! isset($rendered->triggers[$name]) && isset($live[$name]) && Marker::parse($live[$name]->comment) !== null) {
                    $changes[] = new SchemaChange('triggers', 'drop', $name, 'the table no longer needs this trigger', [sprintf('DROP TRIGGER IF EXISTS %s ON %s', Sql::ident($name), $rendered->quotedTable)], true, [$rendered->quotedTable]);
                }
            }
        }

        foreach ($this->markedRoutines() as $routine) {
            if (! isset($wanted[$routine['name']])) {
                $changes[] = new SchemaChange('triggers', 'drop', $routine['name'], 'no registered definition watches this table any more', [sprintf('DROP FUNCTION %s CASCADE', $routine['routine'])], true, [], [$routine['routine']]);
            }
        }

        return $changes;
    }

    /**
     * @return array<string, stdClass>
     */
    private function liveIndexes(): array
    {
        $names = $this->names();
        $live = [];

        foreach ($this->db()->select(
            "SELECT c.relname AS name, i.indisvalid::int AS valid, d.description AS comment FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid LEFT JOIN pg_description d ON d.objoid = c.oid AND d.classoid = 'pg_class'::regclass WHERE i.indrelid IN (to_regclass(?), to_regclass(?)) AND starts_with(c.relname, ?) ORDER BY c.relname",
            [Sql::ident($names->keys()), Sql::ident($names->entries()), $names->prefix()],
        ) as $row) {
            $live[(string) $row->name] = $row;
        }

        return $live;
    }

    /**
     * @return list<SchemaChange>
     */
    private function indexChanges(SchemaPlan $plan): array
    {
        $names = $this->names();
        $live = $this->liveIndexes();
        $relations = [Sql::ident($names->keys()), Sql::ident($names->entries())];
        $changes = [];
        $wanted = [];

        foreach ($plan->indexes as $spec) {
            $wanted[$spec->name] = true;
            $row = $live[$spec->name] ?? null;
            $comment = sprintf('COMMENT ON INDEX %s IS %s', Sql::ident($spec->name), TriggerSql::literal($spec->comment()));

            if ($row === null) {
                $changes[] = new SchemaChange('index', 'create', $spec->name, 'index is missing', [$spec->createSql, $comment], false, $relations);

                continue;
            }

            $reason = (int) $row->valid !== 1 ? 'index is invalid (an interrupted build)' : ($row->comment !== $spec->comment() ? 'index differs from the plan' : null);

            if ($reason !== null) {
                $changes[] = new SchemaChange('index', 'replace', $spec->name, $reason, [$this->dropIndex($plan->schema, $spec->name), $spec->createSql, $comment], false, $relations);
            }
        }

        // An invalid index is never usable, and one nobody plans is only a leftover of a build that died.
        foreach ($live as $name => $row) {
            if (! isset($wanted[$name]) && (int) $row->valid !== 1) {
                $changes[] = new SchemaChange('index', 'drop', $name, 'an interrupted build nobody plans', [$this->dropIndex($plan->schema, $name)], false, $relations);
            }
        }

        return $changes;
    }

    /**
     * @return list<SchemaChange>
     */
    private function obsoleteIndexes(SchemaPlan $plan): array
    {
        $names = $this->names();
        $relations = [Sql::ident($names->keys()), Sql::ident($names->entries())];
        $wanted = array_flip(array_map(static fn (IndexSpec $spec): string => $spec->name, $plan->indexes));
        $changes = [];

        foreach ($this->liveIndexes() as $name => $row) {
            if (! isset($wanted[$name]) && (int) $row->valid === 1 && Marker::parse($row->comment) !== null) {
                $changes[] = new SchemaChange('index', 'drop', $name, 'no registered definition needs this index', [$this->dropIndex($plan->schema, $name)], false, $relations);
            }
        }

        return $changes;
    }

    private function dropIndex(string $schema, string $name): string
    {
        return sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s.%s', Sql::ident($schema), Sql::ident($name));
    }

    /**
     * Reading a trigger definition takes a lock on its table, so inspection must not wait for a
     * writer forever either.
     *
     * @template T
     *
     * @param  callable(): T  $inspect
     * @return T
     */
    private function withLockTimeout(callable $inspect): mixed
    {
        $db = $this->db();

        if ($db->transactionLevel() > 0) {
            return $inspect();
        }

        $previous = $db->selectOne("SELECT current_setting('lock_timeout') AS value");
        $db->selectOne("SELECT set_config('lock_timeout', ?, false)", [$this->lockTimeout]);

        try {
            return $inspect();
        } finally {
            $db->selectOne("SELECT set_config('lock_timeout', ?, false)", [$previous === null ? '0' : (string) $previous->value]);
        }
    }

    private function assertReady(): void
    {
        $this->assertExtension();

        if (! $this->installed()) {
            throw new SchemaNotInstalled('The model search tables do not exist yet; run the migrations first.');
        }
    }

    /**
     * @param  list<SchemaChange>  $changes
     */
    private function assertOwnership(array $changes): void
    {
        $db = $this->db();
        $foreign = [];

        foreach ($changes as $change) {
            foreach ($change->relations as $relation) {
                $row = $db->selectOne('SELECT pg_has_role(current_user, relowner, \'USAGE\') AS owned FROM pg_class WHERE oid = to_regclass(?)', [$relation]);

                if ($row !== null && ! $row->owned) {
                    $foreign[$relation] = true;
                }
            }

            foreach ($change->routines as $routine) {
                $row = $db->selectOne('SELECT pg_has_role(current_user, proowner, \'USAGE\') AS owned FROM pg_proc WHERE oid = to_regprocedure(?)', [$routine]);

                if ($row !== null && ! $row->owned) {
                    $foreign[$routine] = true;
                }
            }
        }

        if ($foreign !== []) {
            $role = $db->selectOne('SELECT current_user AS name');

            throw new NotTableOwner(sprintf(
                'The database role "%s" does not own %s. Run the sync as the role that owns these objects.',
                $role === null ? '?' : (string) $role->name,
                implode(', ', array_keys($foreign)),
            ));
        }
    }

    private function assertExtension(): void
    {
        if ($this->db()->selectOne("SELECT 1 AS present FROM pg_extension WHERE extname = 'pg_trgm'") === null) {
            throw new MissingExtension('The pg_trgm extension is not installed in this database. Run CREATE EXTENSION pg_trgm (it ships with PostgreSQL contrib) and sync again.');
        }
    }

    /**
     * Why the database cannot be changed (read-only session, standby), or null when it can.
     */
    private function readOnlyReason(): ?string
    {
        $row = $this->db()->selectOne("SELECT current_setting('transaction_read_only') = 'on' AS read_only, pg_is_in_recovery() AS recovery");

        if ($row === null) {
            return null;
        }

        return $row->recovery ? 'the database is a standby' : ($row->read_only ? 'the database is read-only' : null);
    }

    /**
     * The trigger functions the package installed in the current schema.
     *
     * @return list<array{routine: string, name: string}>
     */
    private function markedRoutines(): array
    {
        $rows = $this->db()->select(
            "SELECT p.oid::regprocedure::text AS routine, p.proname AS name FROM pg_proc p JOIN pg_description d ON d.objoid = p.oid AND d.classoid = 'pg_proc'::regclass WHERE p.pronamespace = to_regnamespace(current_schema()) AND starts_with(p.proname, ?) AND starts_with(d.description, ?)",
            [$this->names()->prefix(), Marker::PREFIX],
        );

        return array_values(array_map(static fn (stdClass $row): array => ['routine' => (string) $row->routine, 'name' => (string) $row->name], $rows));
    }

    private function tableExists(string $quotedTable): bool
    {
        return $this->db()->selectOne('SELECT to_regclass(?) IS NOT NULL AS present', [$quotedTable])?->present === true;
    }

    private function currentSchema(): string
    {
        $row = $this->db()->selectOne('SELECT current_schema() AS schema');

        return $row === null ? 'public' : (string) $row->schema;
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
