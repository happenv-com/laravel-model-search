<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Session;

use Closure;
use Happenv\ModelSearch\Events\SearchPrivacyUnsafe;
use Happenv\ModelSearch\Exceptions\SearchFailed;
use Happenv\ModelSearch\Exceptions\SearchPrivacyUnavailable;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use PDO;
use Pdo\Pgsql;
use Throwable;

/**
 * Runs the statements of one search on the search connection, in one transaction that owns every
 * setting the search depends on.
 *
 * Every statement that carries a phrase (or a variant of it) goes through here, whichever driver
 * built it. On PostgreSQL the session:
 *
 * - makes the driver send the phrase as a parameter (`PDO::ATTR_EMULATE_PREPARES` off, the
 *   PostgreSQL driver's `ATTR_DISABLE_PREPARES` on, restored afterwards): with emulated prepares
 *   the phrase would be a literal in the statement text, and so in the server's logs;
 * - asks the {@see PrivacyGate} whether the server could log the phrase, and refuses or warns;
 * - starts a transaction and sets, as literals (`SET LOCAL` takes no bindings):
 *   `max_parallel_workers_per_gather = 0` (parallel workers exhaust shared memory under load),
 *   the `statement_timeout` (never longer than the gate allows), `pg_trgm.word_similarity_threshold`
 *   and `log_parameter_max_length_on_error = 0`, which it reads back: an error log must show `$1`,
 *   not the phrase;
 * - turns a statement timeout into {@see SearchTimedOut} and any other database failure into
 *   {@see SearchFailed}, whose message and trace carry neither the SQL nor the phrase.
 *
 * Run inside the caller's transaction, the settings are put back afterwards, because `SET LOCAL`
 * outlives a released savepoint. On another database there is no session to configure and the
 * work just runs.
 */
final class SearchSession
{
    private const string TIMEOUT_STATE = '57014';

    /**
     * @template TResult
     *
     * @param  string  $hostConnection  The name of the host's connection.
     * @param  int  $timeoutMs  The timeout this search wants; the gate may shorten it.
     * @param  Closure(Connection): TResult  $fn  The work, given the search connection.
     * @return TResult
     *
     * @throws SearchPrivacyUnavailable
     * @throws SearchTimedOut
     * @throws SearchFailed
     */
    public function run(string $hostConnection, int $timeoutMs, Closure $fn): mixed
    {
        $connection = resolve(SearchConnectionFactory::class)->make($hostConnection);

        try {
            return $connection->getDriverName() === 'pgsql'
                ? $this->onPostgres($connection, $timeoutMs, $fn)
                : $fn($connection);
        } catch (QueryException $exception) {
            $state = (string) $exception->getCode();

            throw $state === self::TIMEOUT_STATE
                ? new SearchTimedOut('The search ran out of time.')
                : new SearchFailed(sprintf('The search statement failed (SQLSTATE %s).', $state));
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(Connection): TResult  $fn
     * @return TResult
     */
    private function onPostgres(Connection $connection, int $timeoutMs, Closure $fn): mixed
    {
        $pdo = $connection->getPdo();
        $emulated = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $withoutPrepares = $pdo->getAttribute(Pgsql::ATTR_DISABLE_PREPARES);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(Pgsql::ATTR_DISABLE_PREPARES, true);

        try {
            $gate = resolve(PrivacyGate::class);
            $verdict = $gate->verdict($connection);

            if (! $verdict->isSafe() && $gate->refuses()) {
                throw new SearchPrivacyUnavailable($verdict->violations);
            }

            return $this->inTransaction($connection, $verdict->effectiveTimeout($timeoutMs), $gate->refuses(), $fn);
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
            $pdo->setAttribute(Pgsql::ATTR_DISABLE_PREPARES, $withoutPrepares);
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(Connection): TResult  $fn
     * @return TResult
     */
    private function inTransaction(Connection $connection, int $timeoutMs, bool $refuses, Closure $fn): mixed
    {
        $previous = $connection->transactionLevel() > 0 ? $this->current($connection) : null;

        try {
            return $connection->transaction(function () use ($connection, $timeoutMs, $refuses, $fn): mixed {
                $this->configure($connection, $timeoutMs, $refuses);

                return $fn($connection);
            });
        } finally {
            if ($previous !== null) {
                $this->restore($connection, $previous);
            }
        }
    }

    private function configure(Connection $connection, int $timeoutMs, bool $refuses): void
    {
        $threshold = config('model-search.word_similarity_threshold', 0.5);
        $threshold = is_numeric($threshold) && $threshold >= 0 && $threshold <= 1 ? (float) $threshold : 0.5;

        $connection->statement('SET LOCAL max_parallel_workers_per_gather = 0');
        $connection->statement('SET LOCAL statement_timeout = '.$timeoutMs);
        $connection->statement('SET LOCAL pg_trgm.word_similarity_threshold = '.round($threshold, 4));
        $connection->statement('SET LOCAL log_parameter_max_length_on_error = 0');

        $logged = $connection->selectOne("SELECT current_setting('log_parameter_max_length_on_error') AS value");

        if (($logged->value ?? null) === '0') {
            return;
        }

        $violations = ['log_parameter_max_length_on_error could not be set to 0: a failed search could log its phrase'];
        Event::dispatch(new SearchPrivacyUnsafe($violations, $refuses));

        if ($refuses) {
            throw new SearchPrivacyUnavailable($violations);
        }
    }

    /**
     * The values the search settings have in the caller's transaction.
     *
     * @return array<string, string>
     */
    private function current(Connection $connection): array
    {
        $row = $connection->selectOne("SELECT current_setting('max_parallel_workers_per_gather') AS parallel, current_setting('statement_timeout') AS timeout, current_setting('pg_trgm.word_similarity_threshold', true) AS threshold, current_setting('log_parameter_max_length_on_error') AS logged");

        $values = [
            'max_parallel_workers_per_gather' => $row->parallel ?? null,
            'statement_timeout' => $row->timeout ?? null,
            'pg_trgm.word_similarity_threshold' => $row->threshold ?? null,
            'log_parameter_max_length_on_error' => $row->logged ?? null,
        ];

        return array_map(strval(...), array_filter($values, is_string(...)));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function restore(Connection $connection, array $values): void
    {
        try {
            foreach ($values as $name => $value) {
                $connection->selectOne('SELECT set_config(?, ?, true)', [$name, $value]);
            }
        } catch (Throwable) {
            // The caller's transaction was aborted; the settings die with it.
        }
    }
}
