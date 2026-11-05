<?php

declare(strict_types=1);

use Happenv\ModelSearch\Exceptions\SearchFailed;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Happenv\ModelSearch\Postgres\Session\SearchSession;
use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;

covers(SearchSession::class);

function nsSettings(): array
{
    $row = DB::selectOne("SELECT current_setting('statement_timeout') AS timeout, current_setting('max_parallel_workers_per_gather') AS parallel, current_setting('log_parameter_max_length_on_error') AS logged");

    return [$row->timeout, $row->parallel, $row->logged];
}

it('leaves the callers transaction settings as it found them', function (): void {
    DB::statement('SET LOCAL statement_timeout = 4321');
    DB::statement('SET LOCAL max_parallel_workers_per_gather = 3');
    DB::statement('SET LOCAL log_parameter_max_length_on_error = 77');
    $before = nsSettings();

    $inside = app(SearchSession::class)->run('pgsql', 100, static fn (Connection $connection): string => (string) $connection->selectOne("SELECT current_setting('max_parallel_workers_per_gather') AS value")->value);

    expect($inside)->toBe('0')
        ->and(nsSettings())->toBe($before)
        ->and(DB::transactionLevel())->toBe(1);
});

it('sees the callers uncommitted rows', function (): void {
    DB::statement('CREATE TEMP TABLE session_visibility (id int) ON COMMIT DROP');
    DB::statement('INSERT INTO session_visibility VALUES (7)');

    $seen = app(SearchSession::class)->run('pgsql', 100, static fn (Connection $connection): int => (int) $connection->selectOne('SELECT id FROM session_visibility')->id);

    expect($seen)->toBe(7);
});

/**
 * Everything a leaked phrase could hide in: messages, string arguments of trace frames, the previous chain.
 */
function nsLeaks(Throwable $exception, string $phrase): bool
{
    for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
        if (str_contains($current->getMessage(), $phrase) || str_contains($current->getTraceAsString(), $phrase)) {
            return true;
        }

        foreach ($current->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                if (is_string($argument) && str_contains($argument, $phrase)) {
                    return true;
                }
            }
        }
    }

    return false;
}

it('turns a timeout inside a host transaction into SearchTimedOut without the phrase and keeps the transaction usable', function (): void {
    $phrase = 'deadlock detected SECRETPHRASE';
    $caught = null;

    try {
        app(SearchSession::class)->run('pgsql', 30, static fn (Connection $connection): array => $connection->select('SELECT pg_sleep(2) WHERE ? <> \'\'', [$phrase]));
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(SearchTimedOut::class)
        ->and($caught->getPrevious())->toBeNull()
        ->and(nsLeaks($caught, $phrase))->toBeFalse()
        ->and((int) DB::selectOne('SELECT 1 AS one')->one)->toBe(1)
        ->and(DB::transactionLevel())->toBe(1);
});

it('rethrows a genuine deadlock inside a host transaction as a new phrase free DeadlockException', function (): void {
    $phrase = 'SECRETPHRASE';
    DB::statement("CREATE FUNCTION pg_temp.boom(text) RETURNS int LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'deadlock detected %', \$1 USING ERRCODE = '40P01'; END \$\$");
    $caught = null;

    try {
        app(SearchSession::class)->run('pgsql', 300, static fn (Connection $connection): array => $connection->select('SELECT pg_temp.boom(?)', [$phrase]));
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(DeadlockException::class)
        ->and($caught->getPrevious())->toBeNull()
        ->and(nsLeaks($caught, $phrase))->toBeFalse()
        ->and((int) DB::selectOne('SELECT 1 AS one')->one)->toBe(1);
});

it('classifies by SQLSTATE and not by message text', function (): void {
    $phrase = 'SECRETPHRASE';
    DB::statement("CREATE FUNCTION pg_temp.boom2(text) RETURNS int LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'deadlock detected %', \$1 USING ERRCODE = '22012'; END \$\$");

    $run = static fn () => app(SearchSession::class)->run('pgsql', 300, static fn (Connection $connection): array => $connection->select('SELECT pg_temp.boom2(?)', [$phrase]));

    expect($run)->toThrow(SearchFailed::class);
});

it('a beforeExecuting callback of the host never sees the phrase', function (): void {
    $seen = [];
    DB::connection()->beforeExecuting(static function (string $query, array $bindings) use (&$seen): void {
        $seen[] = $query.json_encode($bindings);
    });

    app(SearchSession::class)->run('pgsql', 300, static fn (Connection $connection): array => $connection->select('SELECT ?::text AS value', ['SECRETPHRASE']));

    expect(implode('', $seen))->not->toContain('SECRETPHRASE');
});
