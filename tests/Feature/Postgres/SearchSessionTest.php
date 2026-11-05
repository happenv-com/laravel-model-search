<?php

declare(strict_types=1);

use Happenv\ModelSearch\Events\SearchPrivacyUnsafe;
use Happenv\ModelSearch\Exceptions\SearchFailed;
use Happenv\ModelSearch\Exceptions\SearchPrivacyUnavailable;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Happenv\ModelSearch\Postgres\Session\PrivacyGate;
use Happenv\ModelSearch\Postgres\Session\SearchConnectionFactory;
use Happenv\ModelSearch\Postgres\Session\SearchSession;
use Happenv\ModelSearch\Tests\Fixtures\Session\IgnoringParameterLogFactory;
use Happenv\ModelSearch\Tests\Fixtures\Session\UnreadableSettingsFactory;
use Happenv\ModelSearch\Tests\TruncationTestCase;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Pdo\Pgsql;

uses(TruncationTestCase::class);

covers(SearchSession::class);

const SS_SETTINGS = ['log_statement', 'log_min_duration_statement', 'auto_explain.log_min_duration', 'max_parallel_workers_per_gather', 'log_parameter_max_length_on_error'];

function ssRun(int $timeoutMs, Closure $fn): mixed
{
    return app(SearchSession::class)->run('pgsql', $timeoutMs, $fn);
}

function ssSetting(Connection $connection, string $name): string
{
    return (string) $connection->selectOne('SELECT current_setting(?) AS value', [$name])->value;
}

beforeEach(function (): void {
    app(PrivacyGate::class)->flush();
});

afterEach(function (): void {
    foreach (SS_SETTINGS as $setting) {
        DB::statement('RESET '.$setting);
    }

    DB::getPdo()->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
});

it('sends the phrase as a parameter', function (): void {
    $phrase = 'needle-'.bin2hex(random_bytes(4));
    DB::getPdo()->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $sql = 'SELECT query FROM pg_stat_activity WHERE pid = pg_backend_pid() AND ? = ?';
    $seen = [];
    DB::listen(static function ($query) use (&$seen): void {
        $seen[] = $query->sql.json_encode($query->bindings);
    });
    DB::enableQueryLog();

    $text = ssRun(300, static fn (Connection $connection): string => (string) $connection->selectOne($sql, [$phrase, $phrase])->query);
    $emulated = (string) DB::selectOne($sql, [$phrase, $phrase])->query;

    expect($text)->not->toContain($phrase)
        ->and($text)->toContain('$1')
        ->and($emulated)->toContain($phrase)
        ->and(DB::getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES))->toBeTrue()
        ->and(DB::getPdo()->getAttribute(Pgsql::ATTR_DISABLE_PREPARES))->toBeFalse();

    // The host's own listener and query log saw the host query below, never the search above.
    expect(implode('', array_column(DB::getQueryLog(), 'query')))->not->toContain('pg_stat_activity WHERE pid = pg_backend_pid() AND $1')
        ->and(count(array_filter($seen, static fn (string $entry): bool => str_contains($entry, 'pg_stat_activity'))))->toBe(1);
    DB::disableQueryLog();
});

it('disables parallel workers in every search transaction', function (): void {
    DB::statement('SET max_parallel_workers_per_gather = 4');

    $first = ssRun(300, static fn (Connection $connection): string => ssSetting($connection, 'max_parallel_workers_per_gather'));
    $second = ssRun(300, static fn (Connection $connection): string => ssSetting($connection, 'max_parallel_workers_per_gather'));

    expect($first)->toBe('0')
        ->and($second)->toBe('0')
        ->and((string) DB::selectOne("SELECT current_setting('max_parallel_workers_per_gather') AS value")->value)->toBe('4');
});

it('sets the search settings as literals inside the transaction', function (): void {
    config(['model-search.word_similarity_threshold' => 0.3]);

    $inside = ssRun(250, static fn (Connection $connection): array => [
        'level' => $connection->getPdo()->inTransaction(),
        'timeout' => ssSetting($connection, 'statement_timeout'),
        'threshold' => ssSetting($connection, 'pg_trgm.word_similarity_threshold'),
        'logged' => ssSetting($connection, 'log_parameter_max_length_on_error'),
    ]);

    expect($inside)->toBe(['level' => true, 'timeout' => '250ms', 'threshold' => '0.3', 'logged' => '0']);
});

it('derives the timeout from the lowest positive threshold', function (): void {
    DB::statement('SET log_min_duration_statement = 200');
    DB::statement('SET auto_explain.log_min_duration = 400');

    expect(ssRun(300, static fn (Connection $connection): string => ssSetting($connection, 'statement_timeout')))->toBe('150ms');
});

it('clamps the similar() timeout below the server log thresholds', function (): void {
    DB::statement('SET log_min_duration_statement = 600');
    DB::statement('SET auto_explain.log_min_duration = 800');

    $similar = ssRun((int) config('model-search.similar.timeout_ms'), static fn (Connection $connection): string => ssSetting($connection, 'statement_timeout'));
    $palette = ssRun((int) config('model-search.search.timeout_ms'), static fn (Connection $connection): string => ssSetting($connection, 'statement_timeout'));

    expect($similar)->toBe('550ms')
        ->and($palette)->toBe('300ms');
});

it('refuses on unsafe server', function (): void {
    DB::statement("SET log_statement = 'all'");
    $ran = false;

    try {
        ssRun(300, static function () use (&$ran): void {
            $ran = true;
        });
        $caught = null;
    } catch (SearchPrivacyUnavailable $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(SearchPrivacyUnavailable::class)
        ->and($ran)->toBeFalse()
        ->and(implode(' ', $caught->violations))->toContain('log_statement');
});

it('warn mode searches and reports', function (): void {
    config(['model-search.privacy' => 'warn']);
    DB::statement("SET log_statement = 'all'");
    Event::fake([SearchPrivacyUnsafe::class]);

    $result = ssRun(300, static fn (): string => 'searched');

    expect($result)->toBe('searched');
    Event::assertDispatched(SearchPrivacyUnsafe::class, static fn (SearchPrivacyUnsafe $event): bool => ! $event->refused && str_contains(implode(' ', $event->violations), 'log_statement'));
});

it('verifies log_parameter_max_length_on_error after setting it', function (): void {
    DB::statement('SET log_parameter_max_length_on_error = 100');

    // The real connection overrides the host's value for the transaction...
    expect(ssRun(300, static fn (Connection $connection): string => ssSetting($connection, 'log_parameter_max_length_on_error')))->toBe('0');

    // ...and a connection where the setting did not take is refused.
    app()->instance(SearchConnectionFactory::class, new IgnoringParameterLogFactory);
    $ran = false;

    expect(static fn (): mixed => ssRun(300, static function () use (&$ran): void {
        $ran = true;
    }))->toThrow(SearchPrivacyUnavailable::class)
        ->and($ran)->toBeFalse();
});

it('turns a statement timeout into SearchTimedOut', function (): void {
    expect(static fn (): mixed => ssRun(30, static fn (Connection $connection): array => $connection->select('SELECT pg_sleep(2)')))->toThrow(SearchTimedOut::class);
});

it('rethrows a failing statement without its text', function (): void {
    $phrase = 'needle-'.bin2hex(random_bytes(4));

    try {
        ssRun(300, static fn (Connection $connection): array => $connection->select('SELECT no_such_column_x FROM no_such_table WHERE ? = 1', [$phrase]));
        $caught = null;
    } catch (SearchFailed $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(SearchFailed::class)
        ->and($caught->getMessage())->not->toContain($phrase)->not->toContain('no_such_table')
        ->and($caught->getPrevious())->toBeNull();
});

it('reads the same server the search runs on', function (): void {
    $connection = app(SearchConnectionFactory::class)->make('pgsql');

    expect($connection->getReadPdo())->toBe($connection->getPdo());
});

it('dispatches the privacy event after the search transaction ended', function (): void {
    DB::statement("SET log_statement = 'all'");
    DB::table('orders')->where('signature', 'EVENT-WRITE')->delete();
    Event::listen(SearchPrivacyUnsafe::class, static function (): void {
        DB::table('orders')->insert(['id' => (string) Str::uuid(), 'signature' => 'EVENT-WRITE', 'state' => 'unshipped']);
    });

    try {
        ssRun(300, static fn (): string => 'never');
    } catch (SearchPrivacyUnavailable) {
    }

    expect(DB::table('orders')->where('signature', 'EVENT-WRITE')->count())->toBe(1);
    DB::table('orders')->where('signature', 'EVENT-WRITE')->delete();
});

it('searches in warn mode even when the settings cannot be read', function (): void {
    config(['model-search.privacy' => 'warn']);
    app()->instance(SearchConnectionFactory::class, new UnreadableSettingsFactory);

    expect(ssRun(300, static fn (Connection $connection): int => (int) $connection->selectOne('SELECT 1 AS one')->one))->toBe(1);
});

it('lets a throwing listener neither replace the outcome nor stop the other announcements', function (): void {
    config(['model-search.privacy' => 'warn']);
    DB::statement("SET log_statement = 'all'");
    $later = 0;
    Event::listen(SearchPrivacyUnsafe::class, static function (): never {
        throw new RuntimeException('listener failed');
    });

    expect(ssRun(300, static fn (): string => 'result'))->toBe('result');

    DB::statement("SET log_statement = 'none'");
    app(PrivacyGate::class)->flush();
    DB::statement("SET log_statement = 'all'");
    config(['model-search.privacy' => 'refuse']);

    expect(static fn () => ssRun(300, static fn (): string => 'never'))->toThrow(SearchPrivacyUnavailable::class);
});

function ssSnapshot(): void
{
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::select('SELECT 1');
}

function ssConcurrentUpdate(string $id): void
{
    DB::connectUsing('ss_other', config('database.connections.pgsql'));
    DB::connection('ss_other')->update("UPDATE orders SET state = 'shipped' WHERE id = ?", [$id]);
    DB::purge('ss_other');
}

/**
 * A search that runs into a genuine serialization failure on its first attempt.
 */
function ssSerializationFailure(string $id, int &$attempts, bool $isolate = true): void
{
    $attempts++;

    if ($isolate) {
        ssSnapshot();
    }

    if ($attempts === 1) {
        ssConcurrentUpdate($id);
    }

    app(SearchSession::class)->run('pgsql', 300, static fn (Connection $connection): int => $connection->update("UPDATE orders SET state = 'unshipped' WHERE id = ? AND ? <> ''", [$id, 'SECRETPHRASE']));
}

it('lets Laravel retry a host transaction the search was the victim of', function (): void {
    $id = (string) Str::uuid();
    DB::table('orders')->insert(['id' => $id, 'signature' => 'RETRY-1', 'state' => 'unshipped']);
    $attempts = 0;

    DB::transaction(static function () use ($id, &$attempts): void {
        ssSerializationFailure($id, $attempts);
    }, 2);

    expect($attempts)->toBe(2)
        ->and(DB::table('orders')->where('id', $id)->value('state'))->toBe('unshipped');
    DB::table('orders')->where('id', $id)->delete();
});

it('lets the outer transaction retry when the search failed in a nested one', function (): void {
    $id = (string) Str::uuid();
    DB::table('orders')->insert(['id' => $id, 'signature' => 'RETRY-2', 'state' => 'unshipped']);
    $attempts = 0;

    DB::transaction(static function () use ($id, &$attempts): void {
        ssSnapshot();

        DB::transaction(static function () use ($id, &$attempts): void {
            ssSerializationFailure($id, $attempts, false);
        });
    }, 2);

    expect($attempts)->toBe(2);
    DB::table('orders')->where('id', $id)->delete();
});

it('makes the rethrown deadlock detectable without the phrase', function (): void {
    $caught = null;
    DB::statement("CREATE FUNCTION pg_temp.boom3(text) RETURNS int LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'x %', \$1 USING ERRCODE = '40P01'; END \$\$");
    DB::beginTransaction();

    try {
        ssRun(300, static fn (Connection $connection): array => $connection->select('SELECT pg_temp.boom3(?)', ['SECRETPHRASE']));
    } catch (Throwable $exception) {
        $caught = $exception;
    } finally {
        DB::rollBack();
    }

    expect($caught)->toBeInstanceOf(DeadlockException::class)
        ->and(new ConcurrencyErrorDetector()->causedByConcurrencyError($caught))->toBeTrue()
        ->and($caught->getMessage())->not->toContain('SECRETPHRASE')
        ->and($caught->getPrevious())->toBeNull();
});
