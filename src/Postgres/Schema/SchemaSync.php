<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Compilation\WatchedTable;
use Happenv\ModelSearch\Exceptions\MissingExtension;
use Happenv\ModelSearch\Exceptions\NotTableOwner;
use Happenv\ModelSearch\Exceptions\SchemaNotInstalled;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
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
 * a long writer while blocking the ones behind it. Indexes are built with CONCURRENTLY, outside
 * any transaction; a failed build leaves an invalid index, which the next run drops and rebuilds.
 */
final readonly class SchemaSync
{
    private const string LOCK_TIMEOUT = '2s';

    public function __construct(private ModelSearchRegistry $registry, private ?string $connection = null) {}

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
     * What differs from the database.
     *
     * @return list<SchemaChange>
     */
    public function diff(): array
    {
        $this->assertExtension();

        if (! $this->installed()) {
            throw new SchemaNotInstalled('The model search tables do not exist yet; run the migrations first.');
        }

        $plan = $this->plan();

        return [...$this->triggerChanges($plan), ...$this->indexChanges($plan)];
    }

    public function apply(): SyncOutcome
    {
        $readOnly = $this->readOnlyReason();

        if ($readOnly !== null) {
            return new SyncOutcome([], $readOnly);
        }

        $changes = $this->diff();
        $this->assertOwnership($changes);

        foreach ($changes as $change) {
            $this->execute($change);
        }

        return new SyncOutcome($changes);
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
     * @return list<SchemaChange>
     */
    private function triggerChanges(SchemaPlan $plan): array
    {
        $db = $this->db();
        $sql = new TriggerSql($plan->schema, $this->names());
        $wanted = [];
        $changes = [];

        foreach ($plan->triggers as $rendered) {
            $wanted[$rendered->functionName] = true;

            if (! $this->tableExists($rendered->quotedTable)) {
                continue;
            }

            $reasons = [];
            $function = $db->selectOne(
                "SELECT md5(p.prosrc) AS body, d.description AS comment FROM pg_proc p LEFT JOIN pg_description d ON d.objoid = p.oid AND d.classoid = 'pg_proc'::regclass WHERE p.oid = to_regprocedure(?)",
                [$rendered->qualifiedFunction.'()'],
            );

            if ($function === null) {
                $reasons[] = 'function is missing';
            } elseif ($function->body !== md5($rendered->functionBody) || $function->comment !== Marker::of($rendered->functionSql)) {
                $reasons[] = 'function differs from the definitions';
            }

            $live = [];

            foreach ($db->select(
                "SELECT t.tgname AS name, t.tgenabled AS enabled, d.description AS comment FROM pg_trigger t LEFT JOIN pg_description d ON d.objoid = t.oid AND d.classoid = 'pg_trigger'::regclass WHERE t.tgrelid = to_regclass(?) AND NOT t.tgisinternal",
                [$rendered->quotedTable],
            ) as $row) {
                $live[(string) $row->name] = $row;
            }

            foreach ($rendered->triggers as $name => $createSql) {
                $row = $live[$name] ?? null;

                if ($row === null) {
                    $reasons[] = sprintf('trigger %s is missing', $name);
                } elseif ($row->comment !== Marker::of($createSql)) {
                    $reasons[] = sprintf('trigger %s differs from the definitions', $name);
                } elseif ($row->enabled !== 'O') {
                    $reasons[] = sprintf('trigger %s is disabled', $name);
                }
            }

            foreach ($sql->triggerNames($rendered->table) as $name) {
                $row = $live[$name] ?? null;

                if (! isset($rendered->triggers[$name]) && $row !== null && is_string($row->comment) && str_starts_with($row->comment, Marker::PREFIX)) {
                    $reasons[] = sprintf('trigger %s is no longer needed', $name);
                }
            }

            if ($reasons === []) {
                continue;
            }

            $changes[] = new SchemaChange(
                'triggers',
                $function === null ? 'create' : 'replace',
                $rendered->table,
                implode('; ', $reasons),
                $rendered->statements(),
                true,
                [$rendered->quotedTable],
            );
        }

        foreach ($this->markedRoutines() as $routine) {
            if (isset($wanted[$routine['name']])) {
                continue;
            }

            $changes[] = new SchemaChange(
                'triggers',
                'drop',
                $routine['name'],
                'no registered definition watches this table any more',
                [sprintf('DROP FUNCTION %s CASCADE', $routine['routine'])],
                true,
                [],
                [$routine['routine']],
            );
        }

        return $changes;
    }

    /**
     * @return list<SchemaChange>
     */
    private function indexChanges(SchemaPlan $plan): array
    {
        $db = $this->db();
        $names = $this->names();
        $live = [];

        foreach ($db->select(
            "SELECT c.relname AS name, i.indisvalid::int AS valid, d.description AS comment FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid LEFT JOIN pg_description d ON d.objoid = c.oid AND d.classoid = 'pg_class'::regclass WHERE i.indrelid IN (to_regclass(?), to_regclass(?)) AND starts_with(c.relname, ?)",
            [Sql::ident($names->keys()), Sql::ident($names->entries()), $names->prefix()],
        ) as $row) {
            $live[(string) $row->name] = $row;
        }

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

        foreach ($live as $name => $row) {
            if (! isset($wanted[$name]) && is_string($row->comment) && str_starts_with($row->comment, Marker::PREFIX)) {
                $changes[] = new SchemaChange('index', 'drop', $name, 'no registered definition needs this index', [$this->dropIndex($plan->schema, $name)], false, $relations);
            }
        }

        return $changes;
    }

    private function dropIndex(string $schema, string $name): string
    {
        return sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s.%s', Sql::ident($schema), Sql::ident($name));
    }

    private function execute(SchemaChange $change): void
    {
        $db = $this->db();

        if (! $change->transactional) {
            if ($db->transactionLevel() > 0) {
                throw new LogicException('CREATE INDEX CONCURRENTLY cannot run inside a transaction; run the sync outside of one.');
            }

            foreach ($change->statements as $statement) {
                $db->getPdo()->exec($statement);
            }

            return;
        }

        $db->transaction(function () use ($db, $change): void {
            $previous = $db->selectOne("SELECT current_setting('lock_timeout') AS value");
            $db->selectOne("SELECT set_config('lock_timeout', ?, true)", [self::LOCK_TIMEOUT]);

            foreach ($change->statements as $statement) {
                $db->getPdo()->exec($statement);
            }

            $db->selectOne("SELECT set_config('lock_timeout', ?, true)", [$previous === null ? '0' : (string) $previous->value]);
        });
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
