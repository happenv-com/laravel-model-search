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
use Happenv\ModelSearch\Postgres\Session\PrivacyVerdict;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use stdClass;

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
 * The key checks read the whole key table in one pass; they run with parallel workers off and a
 * statement timeout of their own.
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
                $findings[] = new Finding('type-not-ready', sprintf(
                    $definition->fallback
                        ? '%s has never been indexed completely, so searches of it are served from the source tables.'
                        : '%s has never been indexed completely and has no fallback, so only its exactOnSource fields are searched.',
                    $definition->morphAlias,
                ), $definition->morphAlias);
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
     * One pass over the key table: key rows without an entry, key rows whose stage differs from
     * their entry's, and a check that parameter logging can be switched off. Parallel workers are
     * off and the pass has its own statement timeout (`model-search.doctor.timeout_ms`).
     *
     * @return list<Finding>
     */
    private function keyScans(Connection $db): array
    {
        $keys = $this->names()->quoted($this->names()->keys());
        $entries = $this->names()->quoted($this->names()->entries());
        $timeout = config('model-search.doctor.timeout_ms', 60000);
        $timeout = is_int($timeout) && $timeout > 0 ? $timeout : 60000;

        /** @var list<stdClass> $rows */
        $rows = [];
        $logged = null;

        try {
            $db->transaction(function () use ($db, $keys, $entries, $timeout, &$rows, &$logged): void {
                $previous = $db->selectOne("SELECT current_setting('max_parallel_workers_per_gather') AS parallel, current_setting('statement_timeout') AS timeout, current_setting('log_parameter_max_length_on_error') AS logged");

                $db->statement('SET LOCAL max_parallel_workers_per_gather = 0');
                $db->statement('SET LOCAL statement_timeout = '.$timeout);

                try {
                    // A savepoint of its own: a refusal must not abort the checks below.
                    $logged = $db->transaction(static function () use ($db): mixed {
                        $db->statement('SET LOCAL log_parameter_max_length_on_error = 0');

                        return $db->selectOne("SELECT current_setting('log_parameter_max_length_on_error') AS value")?->value;
                    });
                } catch (QueryException) {
                    $logged = null;
                }

                $rows = $db->select(sprintf(
                    'SELECT k."subject_type", count(*) FILTER (WHERE e."subject_id" IS NULL) AS "orphans", count(*) FILTER (WHERE e."subject_id" IS NOT NULL AND k."stage" IS DISTINCT FROM e."stage") AS "drifted" FROM %1$s k LEFT JOIN %2$s e ON e."subject_type" = k."subject_type" AND e."subject_id" = k."subject_id" GROUP BY k."subject_type" ORDER BY k."subject_type"',
                    $keys,
                    $entries,
                ));

                // `SET LOCAL` outlives a released savepoint, so a caller's transaction gets its values back.
                foreach (['max_parallel_workers_per_gather' => $previous->parallel ?? null, 'statement_timeout' => $previous->timeout ?? null, 'log_parameter_max_length_on_error' => $previous->logged ?? null] as $name => $value) {
                    if (is_string($value)) {
                        $db->selectOne('SELECT set_config(?, ?, true)', [$name, $value]);
                    }
                }
            });
        } catch (QueryException $exception) {
            return [(string) $exception->getCode() === '57014'
                ? new Finding('scan-timed-out', sprintf('The key checks did not finish within %d ms (model-search.doctor.timeout_ms).', $timeout))
                : new Finding('scan-failed', sprintf('The key checks failed (SQLSTATE %s).', $exception->getCode()))];
        }

        $findings = [];

        if ($logged !== '0') {
            $findings[] = new Finding('privacy', 'log_parameter_max_length_on_error cannot be set to 0 for a transaction: a failed search could log its phrase.', 'log_parameter_max_length_on_error');
        }

        foreach ($rows as $row) {
            if ((int) $row->orphans > 0) {
                $findings[] = new Finding('keys-without-entry', sprintf('%d key row(s) of %s have no entry; a rebuild of the type removes them.', $row->orphans, $row->subject_type), (string) $row->subject_type);
            }

            if ((int) $row->drifted > 0) {
                $findings[] = new Finding('key-stage-drift', sprintf('%d key row(s) of %s carry a stage other than their entry\'s; a rebuild of the type repairs them.', $row->drifted, $row->subject_type), (string) $row->subject_type);
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function privacy(Connection $db): array
    {
        // The settings of the server the searches run on: the write connection.
        $verdict = $db->usingWritePdo(fn (): PrivacyVerdict => $this->gate->inspect($db));

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
