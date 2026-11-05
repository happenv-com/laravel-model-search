<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Doctor;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Indexing\RootResolver;
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Postgres\Schema\SchemaChange;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Postgres\Session\PrivacyGate;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Looks for everything that would make the search wrong or slow, and returns it as findings.
 *
 * - the change log is lagging, or holds nodes of a type no definition knows;
 * - the triggers or indexes differ from the definitions, or a planned index is missing or invalid;
 * - a type is not installed, installed for another definition, or its index is not ready yet;
 * - records sit in the failures table;
 * - key rows have no entry, or carry a stage other than their entry's (the drain no longer looks
 *   for the first, and the second is a mirrored column that must never drift);
 * - the database server could write a search phrase to its logs ({@see PrivacyGate}).
 *
 * The two scans over the key table read all of it; they run with parallel workers off.
 */
final readonly class Doctor
{
    public function __construct(
        private ModelSearchRegistry $registry,
        private SchemaSync $sync,
        private SearchIndexer $indexer,
        private IndexState $state,
        private PrivacyGate $gate,
        private ?string $connection = null,
    ) {}

    /**
     * @return list<Finding>
     */
    public function inspect(): array
    {
        $db = $this->db();

        if (! $this->sync->installed()) {
            return [new Finding('tables-missing', 'The model search tables do not exist; run the migrations.')];
        }

        return [
            ...$this->lag(),
            ...$this->schema(),
            ...$this->types(),
            ...$this->failures($db),
            ...$this->unknownNodes($db),
            ...$this->keyScans($db),
            ...$this->privacy($db),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function lag(): array
    {
        $alarm = (int) config('model-search.lag_alarm_seconds', 300);
        $lag = $this->indexer->lag();

        return $alarm > 0 && $lag !== null && $lag >= $alarm
            ? [new Finding('lag', sprintf('The oldest unprocessed change has waited %d s (the alarm is %d s); is the drain running?', $lag, $alarm))]
            : [];
    }

    /**
     * @return list<Finding>
     */
    private function schema(): array
    {
        return array_map(static function (SchemaChange $change): Finding {
            if ($change->kind === 'index') {
                $code = match (true) {
                    $change->action === 'create' => 'index-missing',
                    str_starts_with($change->reason, 'index is invalid') => 'index-invalid',
                    default => 'index-drift',
                };

                return new Finding($code, sprintf('Index %s: %s.', $change->subject, $change->reason), $change->subject);
            }

            return new Finding('trigger-drift', sprintf('Triggers on %s: %s.', $change->subject, $change->reason), $change->subject);
        }, $this->sync->diff());
    }

    /**
     * @return list<Finding>
     */
    private function types(): array
    {
        $findings = [];
        $uninstalled = $this->sync->uninstalled();

        foreach ($uninstalled as $type => $reason) {
            $findings[] = new Finding($reason === 'not installed' ? 'type-not-installed' : 'fingerprint-differs', sprintf('%s: %s.', $type, $reason), $type);
        }

        foreach ($this->registry->all() as $definition) {
            $row = $this->state->find($definition->morphAlias);

            if ($row !== null && $row['ready_at'] === null) {
                $findings[] = new Finding('type-not-ready', sprintf('%s has never been indexed completely, so searches of it use the fallback.', $definition->morphAlias), $definition->morphAlias);
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function failures(Connection $db): array
    {
        $table = $this->names()->quoted($this->names()->failures());
        $findings = [];

        foreach ($db->select(sprintf('SELECT "subject_type", count(*) AS "records" FROM %s GROUP BY "subject_type" ORDER BY "subject_type"', $table)) as $row) {
            $findings[] = new Finding('failures', sprintf('%d record(s) of %s could not be indexed (see the failures table).', $row->records, $row->subject_type), (string) $row->subject_type);
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function unknownNodes(Connection $db): array
    {
        $resolver = new RootResolver($this->names());
        $known = [];

        foreach ($this->registry->all() as $definition) {
            foreach ($resolver->nodeTypesOf($definition) as $type) {
                $known[$type] = true;
            }
        }

        $findings = [];
        $rows = $db->select(
            sprintf('SELECT "node_type", count(*) AS "nodes" FROM %s WHERE "node_type" <> ALL(?::text[]) GROUP BY "node_type" ORDER BY "node_type"', $this->names()->quoted($this->names()->changes())),
            [Sql::arrayLiteral(array_map(strval(...), array_keys($known)))],
        );

        foreach ($rows as $row) {
            $findings[] = new Finding('unknown-nodes', sprintf('%d change(s) name nodes of the type "%s", which no registered definition watches.', $row->nodes, $row->node_type), (string) $row->node_type);
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function keyScans(Connection $db): array
    {
        $keys = $this->names()->quoted($this->names()->keys());
        $entries = $this->names()->quoted($this->names()->entries());

        /** @var list<stdClass> $orphans */
        $orphans = [];
        /** @var list<stdClass> $drifted */
        $drifted = [];

        $db->transaction(function () use ($db, $keys, $entries, &$orphans, &$drifted): void {
            $previous = $db->selectOne("SELECT current_setting('max_parallel_workers_per_gather') AS value")?->value;
            $db->statement('SET LOCAL max_parallel_workers_per_gather = 0');

            try {
                $orphans = $db->select(sprintf('SELECT k."subject_type", count(*) AS "keys" FROM %1$s k WHERE NOT EXISTS (SELECT 1 FROM %2$s e WHERE e."subject_type" = k."subject_type" AND e."subject_id" = k."subject_id") GROUP BY k."subject_type" ORDER BY k."subject_type"', $keys, $entries));
                $drifted = $db->select(sprintf('SELECT k."subject_type", count(*) AS "keys" FROM %1$s k JOIN %2$s e ON e."subject_type" = k."subject_type" AND e."subject_id" = k."subject_id" WHERE k."stage" IS DISTINCT FROM e."stage" GROUP BY k."subject_type" ORDER BY k."subject_type"', $keys, $entries));
            } finally {
                // `SET LOCAL` outlives a released savepoint, so a caller's transaction gets its value back.
                try {
                    $db->selectOne("SELECT set_config('max_parallel_workers_per_gather', ?, true)", [is_string($previous) ? $previous : '2']);
                } catch (Throwable) {
                }
            }
        });

        $findings = [];

        foreach ($orphans as $row) {
            $findings[] = new Finding('keys-without-entry', sprintf('%d key row(s) of %s have no entry; a rebuild of the type removes them.', $row->keys, $row->subject_type), (string) $row->subject_type);
        }

        foreach ($drifted as $row) {
            $findings[] = new Finding('key-stage-drift', sprintf('%d key row(s) of %s carry a stage other than their entry\'s; a rebuild of the type repairs them.', $row->keys, $row->subject_type), (string) $row->subject_type);
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function privacy(Connection $db): array
    {
        $verdict = $this->gate->inspect($db);

        return array_map(static fn (string $violation): Finding => new Finding('privacy', $violation), $verdict->violations);
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
