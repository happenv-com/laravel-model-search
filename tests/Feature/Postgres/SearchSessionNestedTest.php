<?php

declare(strict_types=1);

use Happenv\ModelSearch\Postgres\Session\SearchSession;
use Illuminate\Database\Connection;
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
