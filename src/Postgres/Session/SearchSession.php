<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Session;

use ArrayObject;
use Closure;
use Happenv\ModelSearch\Events\SearchPrivacyUnsafe;
use Happenv\ModelSearch\Exceptions\SearchFailed;
use Happenv\ModelSearch\Exceptions\SearchPrivacyUnavailable;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use PDO;
use Pdo\Pgsql;
use PDOException;
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
 * The transaction is issued by hand (`BEGIN`/`COMMIT`, or `SAVEPOINT model_search` inside the host's
 * transaction), never with `Connection::transaction()`: that path rethrows a deadlock as a
 * `DeadlockException` carrying the SQL and its bindings, and with them the phrase. Every database
 * failure is classified by SQLSTATE (never by message text) and rethrown as a new exception with no
 * `previous`: 57014 is {@see SearchTimedOut}, a genuine 40001/40P01 inside the host's transaction is
 * a new phrase-free `DeadlockException` (so the host's own retry still works), anything else is
 * {@see SearchFailed}. A failed search is rolled back to its savepoint, so the host's transaction
 * stays usable.
 *
 * Inside the host's transaction the settings are put back afterwards, because `SET LOCAL` outlives a
 * released savepoint. The session is the host's own (ruling R16, see {@see SearchConnectionFactory}):
 * it sees the host's uncommitted rows and, under read/write splitting, the search runs on the
 * primary. On another database there is no session to configure and the work just runs.
 *
 * @internal
 */
final class SearchSession
{
    private const string TIMEOUT_STATE = '57014';

    private const string SAVEPOINT = 'model_search';

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
     * @throws DeadlockException
     */
    public function run(string $hostConnection, int $timeoutMs, Closure $fn): mixed
    {
        $connection = resolve(SearchConnectionFactory::class)->make($hostConnection);
        $announcements = new ArrayObject;

        try {
            if ($connection->getDriverName() !== 'pgsql') {
                return $fn($connection);
            }

            return $this->onPostgres($connection, $timeoutMs, $fn, $announcements);
        } catch (SearchPrivacyUnavailable|SearchTimedOut|SearchFailed $exception) {
            throw $exception;
        } catch (PDOException|QueryException $exception) {
            throw $this->withoutDetails($exception, $connection);
        } finally {
            // Outside the search transaction: a listener may write to the database.
            foreach ($announcements as $announce) {
                try {
                    $announce();
                } catch (Throwable $listenerFailure) {
                    // A failing listener must neither replace the search's outcome nor skip the others.
                    report($listenerFailure);
                }
            }
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(Connection): TResult  $fn
     * @param  ArrayObject<int, Closure(): void>  $announcements  Events to dispatch once the search transaction has ended.
     * @return TResult
     */
    private function onPostgres(Connection $connection, int $timeoutMs, Closure $fn, ArrayObject $announcements): mixed
    {
        $pdo = $connection->getPdo();
        $emulated = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $withoutPrepares = $pdo->getAttribute(Pgsql::ATTR_DISABLE_PREPARES);

        try {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $pdo->setAttribute(Pgsql::ATTR_DISABLE_PREPARES, true);

            return $this->inTransaction($connection, $pdo, $timeoutMs, $fn, $announcements);
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
            $pdo->setAttribute(Pgsql::ATTR_DISABLE_PREPARES, $withoutPrepares);
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(Connection): TResult  $fn
     * @param  ArrayObject<int, Closure(): void>  $announcements  Events to dispatch once the search transaction has ended.
     * @return TResult
     */
    private function inTransaction(Connection $connection, PDO $pdo, int $timeoutMs, Closure $fn, ArrayObject $announcements): mixed
    {
        $nested = $pdo->inTransaction();
        $pdo->exec($nested ? 'SAVEPOINT '.self::SAVEPOINT : 'BEGIN');

        try {
            $previous = $nested ? $this->current($connection) : null;
            $gate = resolve(PrivacyGate::class);
            [$verdict, $fresh] = $gate->read($connection);
            $refuses = $gate->refuses();

            if ($fresh && ! $verdict->isSafe()) {
                $announcements[] = static fn () => $gate->announce($verdict);
            }

            if (! $verdict->isSafe() && $refuses) {
                throw new SearchPrivacyUnavailable($verdict->violations);
            }

            $this->configure($connection, $verdict->effectiveTimeout($timeoutMs), $refuses, $announcements);
            $result = $fn($connection);

            if ($previous !== null) {
                $this->restore($connection, $previous);
            }

            $pdo->exec($nested ? 'RELEASE SAVEPOINT '.self::SAVEPOINT : 'COMMIT');

            return $result;
        } catch (Throwable $exception) {
            $this->abandon($pdo, $nested);

            throw $exception;
        }
    }

    private function abandon(PDO $pdo, bool $nested): void
    {
        try {
            if ($nested) {
                // The rollback also puts back every `SET LOCAL` the search made.
                $pdo->exec('ROLLBACK TO SAVEPOINT '.self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT '.self::SAVEPOINT);
            } else {
                $pdo->exec('ROLLBACK');
            }
        } catch (Throwable) {
            // The connection is gone; there is nothing left to put back.
        }
    }

    /**
     * @param  ArrayObject<int, Closure(): void>  $announcements  Events to dispatch once the search transaction has ended.
     */
    private function configure(Connection $connection, int $timeoutMs, bool $refuses, ArrayObject $announcements): void
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
        $announcements[] = static function () use ($violations, $refuses): void {
            Event::dispatch(new SearchPrivacyUnsafe($violations, $refuses));
        };

        if ($refuses) {
            throw new SearchPrivacyUnavailable($violations);
        }
    }

    /**
     * Turns a database failure into an exception that holds neither the SQL nor its bindings, by
     * SQLSTATE, with no `previous`.
     */
    private function withoutDetails(Throwable $exception, Connection $connection): Throwable
    {
        $state = $this->stateOf($exception);

        if ($state === self::TIMEOUT_STATE) {
            return new SearchTimedOut('The search ran out of time.');
        }

        // A deadlock or serialization failure inside the host's own transaction is the host's to retry.
        if (in_array($state, ['40001', '40P01'], true) && $connection->getPdo()->inTransaction()) {
            return $this->retryable();
        }

        return new SearchFailed(sprintf('The search statement failed (SQLSTATE %s).', $state));
    }

    /**
     * A deadlock Laravel recognises as one, so the host's transaction retries: its detector looks at
     * the code (`40001`) and at PostgreSQL's wording, not at the class.
     */
    private function retryable(): DeadlockException
    {
        $exception = new DeadlockException('deadlock detected (SQLSTATE 40P01 or 40001); the search was the victim');
        (function (): void {
            $this->code = '40001';
        })->call($exception);

        return $exception;
    }

    private function stateOf(Throwable $exception): string
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && isset($current->errorInfo[0]) && is_string($current->errorInfo[0])) {
                return $current->errorInfo[0];
            }

            if ($current instanceof QueryException && preg_match('/^[0-9A-Z]{5}$/', (string) $current->getCode()) === 1) {
                return (string) $current->getCode();
            }
        }

        return (string) $exception->getCode();
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
            // Not loaded yet in this session: the module's own default is what loading it will give.
            'pg_trgm.word_similarity_threshold' => $row->threshold ?? '0.6',
            'log_parameter_max_length_on_error' => $row->logged ?? null,
        ];

        return array_map(strval(...), array_filter($values, is_string(...)));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function restore(Connection $connection, array $values): void
    {
        foreach ($values as $name => $value) {
            $connection->selectOne('SELECT set_config(?, ?, true)', [$name, $value]);
        }
    }
}
