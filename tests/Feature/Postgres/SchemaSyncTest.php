<?php

declare(strict_types=1);

use Happenv\ModelSearch\Exceptions\MissingExtension;
use Happenv\ModelSearch\Exceptions\NotTableOwner;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Tests\TruncationTestCase;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(TruncationTestCase::class);

covers(SchemaSync::class);

function sync(): SchemaSync
{
    return app(SchemaSync::class);
}

function triggerNames(): array
{
    return DB::table('pg_trigger')->where('tgisinternal', false)->where('tgname', 'like', 'model_search\_%')->orderBy('tgname')->pluck('tgname')->all();
}

function indexExists(string $name): bool
{
    return DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS present', [$name])->present;
}

afterEach(function (): void {
    DB::statement('RESET ROLE');
    DB::statement('SET default_transaction_read_only = off');
    DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    DB::statement('DROP ROLE IF EXISTS model_search_probe_role');
    sync()->apply();
});

it('is in sync right after the migrations ran', function (): void {
    expect(sync()->diff())->toBe([]);
});

it('migrations ended installs triggers', function (): void {
    DB::statement('DROP FUNCTION model_search_orders_fn() CASCADE');
    DB::statement('DROP INDEX model_search_keys_key_idx');
    expect(triggerNames())->not->toContain('model_search_orders_ins');

    event(new MigrationsEnded('up'));

    expect(triggerNames())->toContain('model_search_orders_ins', 'model_search_orders_upd', 'model_search_orders_del', 'model_search_orders_trunc')
        ->and(indexExists('model_search_keys_key_idx'))->toBeTrue()
        ->and(sync()->diff())->toBe([]);
});

it('migrations ended ignores a rollback and a pretend run', function (): void {
    DB::statement('DROP FUNCTION model_search_orders_fn() CASCADE');

    event(new MigrationsEnded('down'));
    event(new MigrationsEnded('up', ['pretend' => true]));

    expect(triggerNames())->not->toContain('model_search_orders_ins');
});

it('plans a comment with the hash of every statement it owns', function (): void {
    $comment = DB::selectOne("SELECT obj_description('model_search_keys_key_idx'::regclass, 'pg_class') AS comment")->comment;

    expect($comment)->toMatch('/^model-search:[0-9a-f]{64}$/');
});

it('--check fails on drift', function (): void {
    $this->artisan('model-search:sync', ['--check' => true])->assertSuccessful();

    DB::statement('DROP TRIGGER model_search_market_orders_upd ON market_orders');
    $this->artisan('model-search:sync', ['--check' => true])->assertFailed()->expectsOutputToContain('market_orders');

    sync()->apply();
    DB::statement('ALTER TABLE orders DISABLE TRIGGER model_search_orders_ins');
    $this->artisan('model-search:sync', ['--check' => true])->assertFailed()->expectsOutputToContain('disabled');

    sync()->apply();
    DB::statement('CREATE OR REPLACE FUNCTION model_search_orders_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END; $$');
    $this->artisan('model-search:sync', ['--check' => true])->assertFailed()->expectsOutputToContain('function');

    sync()->apply();
    DB::statement('DROP INDEX model_search_keys_reverse_idx');
    $this->artisan('model-search:sync', ['--check' => true])->assertFailed()->expectsOutputToContain('model_search_keys_reverse_idx');

    sync()->apply();
    $this->artisan('model-search:sync', ['--check' => true])->assertSuccessful();
});

it('--dry-run prints DDL and changes nothing', function (): void {
    DB::statement('DROP TRIGGER model_search_orders_ins ON orders');
    DB::statement('DROP INDEX model_search_keys_key_s0_idx');
    $before = triggerNames();

    $this->artisan('model-search:sync', ['--dry-run' => true])
        ->expectsOutputToContain('CREATE OR REPLACE FUNCTION')
        ->expectsOutputToContain('CREATE OR REPLACE TRIGGER "model_search_orders_ins"')
        ->expectsOutputToContain('CREATE INDEX CONCURRENTLY IF NOT EXISTS "model_search_keys_key_s0_idx"')
        ->assertSuccessful();

    expect(triggerNames())->toBe($before)
        ->and(indexExists('model_search_keys_key_s0_idx'))->toBeFalse();
});

it('rebuilds an invalid index left by an interrupted build and drops indexes nobody plans', function (): void {
    DB::statement('UPDATE pg_index SET indisvalid = false WHERE indexrelid = ?::regclass', ['model_search_keys_name_word_idx']);
    DB::statement('CREATE INDEX model_search_keys_stale_idx ON model_search_keys (key)');
    DB::statement("COMMENT ON INDEX model_search_keys_stale_idx IS 'model-search:stale'");

    $changes = array_map(static fn ($change): string => $change->action.' '.$change->subject, sync()->diff());
    expect($changes)->toBe(['replace model_search_keys_name_word_idx', 'drop model_search_keys_stale_idx']);

    sync()->apply();

    expect(indexExists('model_search_keys_stale_idx'))->toBeFalse()
        ->and(DB::selectOne("SELECT indisvalid::int AS valid FROM pg_index WHERE indexrelid = 'model_search_keys_name_word_idx'::regclass")->valid)->toBe(1)
        ->and(sync()->diff())->toBe([]);
});

it('drops the triggers of a table no definition watches any more, and only our own', function (): void {
    DB::statement('CREATE FUNCTION model_search_gone_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END; $$');
    DB::statement("COMMENT ON FUNCTION model_search_gone_fn() IS 'model-search:old'");
    DB::statement('CREATE TRIGGER model_search_countries_ins AFTER INSERT ON countries FOR EACH ROW EXECUTE FUNCTION model_search_gone_fn()');
    DB::statement('CREATE FUNCTION unrelated_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END; $$');
    DB::statement('CREATE TRIGGER unrelated_trg AFTER INSERT ON countries FOR EACH ROW EXECUTE FUNCTION unrelated_fn()');

    expect(array_map(static fn ($change): string => $change->action.' '.$change->subject, sync()->diff()))->toBe(['drop model_search_gone_fn']);

    sync()->apply();

    $names = DB::table('pg_trigger')->where('tgisinternal', false)->pluck('tgname')->all();
    expect($names)->not->toContain('model_search_countries_ins')->toContain('unrelated_trg');

    DB::statement('DROP TRIGGER unrelated_trg ON countries');
    DB::statement('DROP FUNCTION unrelated_fn()');
});

it('applies nothing twice', function (): void {
    DB::statement('DROP TRIGGER model_search_orders_ins ON orders');

    expect(sync()->apply()->applied)->toHaveCount(1)
        ->and(sync()->apply()->applied)->toBe([]);
});

it('refuses as non-owner', function (): void {
    DB::statement('CREATE ROLE model_search_probe_role NOLOGIN');
    DB::statement('DROP TRIGGER model_search_orders_ins ON orders');
    DB::statement('SET ROLE model_search_probe_role');

    expect(fn () => sync()->apply())->toThrow(NotTableOwner::class, 'model_search_probe_role');

    DB::statement('RESET ROLE');
    expect(triggerNames())->not->toContain('model_search_orders_ins');
});

it('skips a read-only database', function (): void {
    DB::statement('DROP TRIGGER model_search_orders_ins ON orders');
    DB::statement('SET default_transaction_read_only = on');

    $outcome = sync()->apply();

    DB::statement('SET default_transaction_read_only = off');
    expect($outcome->applied)->toBe([])
        ->and($outcome->skipped)->toBe('the database is read-only')
        ->and(triggerNames())->not->toContain('model_search_orders_ins');
});

it('does not fail the migration loop on a read-only database', function (): void {
    DB::statement('DROP TRIGGER model_search_orders_ins ON orders');
    DB::statement('SET default_transaction_read_only = on');

    event(new MigrationsEnded('up'));

    DB::statement('SET default_transaction_read_only = off');
    expect(triggerNames())->not->toContain('model_search_orders_ins');
});

it('removes the triggers before the package tables are dropped', function (): void {
    $migration = require __DIR__.'/../../../database/migrations/2026_11_05_000000_create_model_search_tables.php';

    $migration->down();

    // The host tables still accept writes: no trigger points at the dropped changes table.
    DB::table('addresses')->insert(['id' => (string) Str::uuid(), 'name' => 'Ann']);
    expect(triggerNames())->toBe([]);

    $migration->up();
});

it('names the missing extension instead of failing at the first fuzzy query', function (): void {
    DB::statement('DROP EXTENSION pg_trgm CASCADE');

    expect(fn () => sync()->diff())->toThrow(MissingExtension::class, 'pg_trgm')
        ->and(fn () => sync()->apply())->toThrow(MissingExtension::class);
});

it('refuses to build an index concurrently inside a transaction', function (): void {
    DB::statement('DROP INDEX model_search_keys_key_idx');

    DB::beginTransaction();

    try {
        expect(fn () => sync()->apply())->toThrow(LogicException::class, 'CONCURRENTLY');
    } finally {
        DB::rollBack();
    }
});

it('removes every trigger function when uninstalled', function (): void {
    sync()->uninstall();

    expect(triggerNames())->toBe([]);
});

it('registers the sync listener on the migration event', function (): void {
    expect(Event::hasListeners(MigrationsEnded::class))->toBeTrue();
});
