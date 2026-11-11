<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Exceptions\MissingExtension;
use Happenv\ModelSearch\Exceptions\NotTableOwner;
use Happenv\ModelSearch\Exceptions\SchemaSyncIncomplete;
use Happenv\ModelSearch\Listeners\SyncAfterMigrations;
use Happenv\ModelSearch\Postgres\Schema\SchemaChange;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\ConnectedOrder;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConnectedOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\CustomerSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\InvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ParcelSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ProductSearch;
use Happenv\ModelSearch\Tests\TruncationTestCase;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(TruncationTestCase::class);

covers(SchemaSync::class);

function sync(): SchemaSync
{
    return app(SchemaSync::class);
}

/**
 * @return list<string>
 */
function triggerNames(): array
{
    return DB::table('pg_trigger')->where('tgisinternal', false)->where('tgname', 'like', 'model_search\_%')->orderBy('tgname')->pluck('tgname')->all();
}

function indexExists(string $name): bool
{
    return DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS present', [$name])->present;
}

/** The name the current plan gives to the index of a family (the name carries a hash of the definition). */
function indexName(string $family): string
{
    foreach (sync()->plan()->indexes as $spec) {
        if ($spec->family === $family) {
            return $spec->name;
        }
    }

    throw new LogicException('No planned index of family '.$family);
}

/**
 * @param  list<SchemaChange>  $changes
 * @return list<string>
 */
function describeChanges(array $changes): array
{
    return array_map(static fn (SchemaChange $change): string => $change->action.' '.$change->subject, $changes);
}

beforeEach(function (): void {
    // Truncation empties the state table; `--check` also reports types that are not installed.
    sync()->apply();
});

afterEach(function (): void {
    DB::purge('second');
    DB::statement('RESET ROLE');
    DB::statement('SET default_transaction_read_only = off');
    DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    DB::statement('DROP ROLE IF EXISTS model_search_probe_role');
    ConfigurableOrderSearch::reset();
    sync()->apply(prune: true);
});

it('is in sync right after the migrations ran', function (): void {
    expect(sync()->diff())->toBe([])
        ->and(sync()->obsolete())->toBe([]);
});

it('migrations ended installs triggers', function (): void {
    DB::statement('DROP FUNCTION IF EXISTS model_search_orders_fn() CASCADE');
    DB::statement('DROP INDEX '.indexName('keys_key'));
    expect(triggerNames())->not->toContain('model_search_orders_ins');

    event(new MigrationsEnded('up'));

    expect(triggerNames())->toContain('model_search_orders_ins', 'model_search_orders_upd', 'model_search_orders_del', 'model_search_orders_trunc')
        ->and(indexExists(indexName('keys_key')))->toBeTrue()
        ->and(sync()->diff())->toBe([]);
});

it('migrations ended installs the triggers when the migrator runs on the direct variant of the connection', function (): void {
    // Production: a pooled connection makes Laravel's migrator switch the default to `pgsql::direct` for the whole run,
    // while a model that names its connection keeps naming `pgsql`. The sync compared the two names and planned nothing.
    $previousRegistry = app(ModelSearchRegistry::class);
    app()->instance(ModelSearchRegistry::class, tap(new ModelSearchRegistry, static fn (ModelSearchRegistry $registry) => $registry->register(ConnectedOrderSearch::class)));
    DB::statement('DROP FUNCTION IF EXISTS model_search_orders_fn() CASCADE');

    try {
        DB::usingConnection('pgsql::direct', static fn () => event(new MigrationsEnded('up')));

        expect(triggerNames())->toContain('model_search_orders_ins', 'model_search_orders_upd', 'model_search_orders_del', 'model_search_orders_trunc')
            ->and(DB::table(TableNames::forConnection()->state())->where('subject_type', (new ConnectedOrder)->getMorphClass())->exists())->toBeTrue();
    } finally {
        // The truncation case keeps DDL: hand the fixture definitions back before afterEach syncs with prune.
        app()->instance(ModelSearchRegistry::class, $previousRegistry);
    }
});

it('migrations ended reports a sync that was skipped', function (): void {
    Log::spy();
    DB::statement('SET default_transaction_read_only = on');

    try {
        event(new MigrationsEnded('up'));
    } finally {
        DB::statement('SET default_transaction_read_only = off');
    }

    Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_contains($message, 'the sync after the migrations was skipped (the database is read-only)'))->once();
});

it('migrations ended ignores a rollback and a pretend run', function (): void {
    DB::statement('DROP FUNCTION model_search_orders_fn() CASCADE');

    event(new MigrationsEnded('down'));
    event(new MigrationsEnded('up', ['pretend' => true]));

    expect(triggerNames())->not->toContain('model_search_orders_ins');
});

it('registers SyncAfterMigrations itself as the migration listener', function (): void {
    $listeners = array_map(
        static fn (Closure $listener): mixed => (new ReflectionFunction($listener))->getStaticVariables()['listener'] ?? null,
        Event::getListeners(MigrationsEnded::class),
    );

    expect($listeners)->toContain(SyncAfterMigrations::class);
});

it('stamps the plan hash on an index, and the plan hash with the live definition on a trigger and a function', function (): void {
    $index = DB::selectOne("SELECT obj_description(?::regclass, 'pg_class') AS comment", [indexName('keys_key')])->comment;
    $trigger = DB::selectOne("SELECT d.description AS comment FROM pg_trigger t JOIN pg_description d ON d.objoid = t.oid WHERE t.tgname = 'model_search_orders_ins'")->comment;
    $function = DB::selectOne("SELECT obj_description('model_search_orders_fn()'::regprocedure, 'pg_proc') AS comment")->comment;

    expect($index)->toMatch('/^model-search:[0-9a-f]{64}$/')
        ->and($trigger)->toMatch('/^model-search:[0-9a-f]{64}:[0-9a-f]{32}$/')
        ->and($function)->toMatch('/^model-search:[0-9a-f]{64}:[0-9a-f]{32}$/');
});

it('stamps definitions rendered under a fixed search_path, so the session path never causes drift', function (): void {
    $stamped = DB::selectOne("SELECT d.description AS comment, t.oid FROM pg_trigger t JOIN pg_description d ON d.objoid = t.oid WHERE t.tgname = 'model_search_orders_ins'");

    DB::transaction(function () use ($stamped): void {
        DB::statement('SET LOCAL search_path = pg_catalog');
        $fixed = DB::selectOne('SELECT md5(pg_get_triggerdef(?::oid)) AS md5', [$stamped->oid])->md5;

        expect($stamped->comment)->toEndWith(':'.$fixed);
    });

    foreach (['public, pg_catalog', 'pg_catalog, public', 'public'] as $path) {
        DB::statement('SET search_path = '.$path);
        $diff = sync()->diff();
        DB::statement('SET search_path = public');

        expect($diff)->toBe([], $path);
    }
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
    DB::statement('DROP INDEX '.indexName('keys_reverse'));
    $this->artisan('model-search:sync', ['--check' => true])->assertFailed()->expectsOutputToContain(indexName('keys_reverse'));

    sync()->apply();
    $this->artisan('model-search:sync', ['--check' => true])->assertSuccessful();
});

it('--check fails on a trigger whose definition was edited behind the comment', function (): void {
    // CREATE OR REPLACE TRIGGER keeps the object, so the comment (the plan hash) survives the edit.
    DB::statement('CREATE OR REPLACE TRIGGER model_search_orders_upd AFTER UPDATE OF state ON orders FOR EACH ROW WHEN (false) EXECUTE FUNCTION model_search_orders_fn()');

    $this->artisan('model-search:sync', ['--check' => true])->assertFailed()->expectsOutputToContain('model_search_orders_upd');

    sync()->apply();
    $this->artisan('model-search:sync', ['--check' => true])->assertSuccessful();
});

it('--check fails on a function whose attributes were changed behind the comment', function (): void {
    DB::statement('ALTER FUNCTION model_search_orders_fn() SECURITY DEFINER');

    $this->artisan('model-search:sync', ['--check' => true])->assertFailed()->expectsOutputToContain('function');

    sync()->apply();
    expect(DB::selectOne("SELECT prosecdef FROM pg_proc WHERE proname = 'model_search_orders_fn'")->prosecdef)->toBeFalse();
});

it('--dry-run prints DDL and changes nothing', function (): void {
    DB::statement('DROP TRIGGER model_search_orders_ins ON orders');
    DB::statement('DROP INDEX '.indexName('keys_key_s0'));
    $before = triggerNames();

    $this->artisan('model-search:sync', ['--dry-run' => true])
        ->expectsOutputToContain('CREATE OR REPLACE FUNCTION')
        ->expectsOutputToContain('CREATE OR REPLACE TRIGGER "model_search_orders_ins"')
        ->expectsOutputToContain('CREATE INDEX CONCURRENTLY IF NOT EXISTS "'.indexName('keys_key_s0').'"')
        ->assertSuccessful();

    expect(triggerNames())->toBe($before)
        ->and(indexExists(indexName('keys_key_s0')))->toBeFalse();
});

it('rebuilds an invalid index and drops an invalid leftover of ours, but keeps a valid unplanned one', function (): void {
    DB::statement('UPDATE pg_index SET indisvalid = false WHERE indexrelid = ?::regclass', [indexName('keys_name_word')]);
    // An interrupted build of an index nobody plans (no comment: the build died before it).
    DB::statement('CREATE INDEX model_search_keys_leftover_idx ON model_search_keys (key)');
    DB::statement('UPDATE pg_index SET indisvalid = false WHERE indexrelid = ?::regclass', ['model_search_keys_leftover_idx']);
    DB::statement('CREATE INDEX model_search_keys_stale_idx ON model_search_keys (key)');
    DB::statement("COMMENT ON INDEX model_search_keys_stale_idx IS 'model-search:stale'");

    expect(describeChanges(sync()->diff()))->toBe(['replace '.indexName('keys_name_word'), 'drop model_search_keys_leftover_idx']);

    sync()->apply();

    expect(indexExists('model_search_keys_leftover_idx'))->toBeFalse()
        ->and(DB::selectOne('SELECT indisvalid::int AS valid FROM pg_index WHERE indexrelid = ?::regclass', [indexName('keys_name_word')])->valid)->toBe(1)
        ->and(indexExists('model_search_keys_stale_idx'))->toBeTrue();
});

it('never drops on apply: an unplanned trigger function and index survive, are listed, and go with --prune', function (): void {
    DB::statement('DROP TRIGGER IF EXISTS unrelated_trg ON countries');
    DB::statement('DROP FUNCTION IF EXISTS unrelated_fn()');
    DB::statement('DROP INDEX IF EXISTS model_search_keys_stale_idx');
    DB::statement('CREATE FUNCTION model_search_gone_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END; $$');
    DB::statement("COMMENT ON FUNCTION model_search_gone_fn() IS 'model-search:old'");
    DB::statement('CREATE TRIGGER model_search_countries_ins AFTER INSERT ON countries FOR EACH ROW EXECUTE FUNCTION model_search_gone_fn()');
    DB::statement('CREATE INDEX model_search_keys_stale_idx ON model_search_keys (key)');
    DB::statement("COMMENT ON INDEX model_search_keys_stale_idx IS 'model-search:stale'");
    DB::statement('CREATE FUNCTION unrelated_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END; $$');
    DB::statement('CREATE TRIGGER unrelated_trg AFTER INSERT ON countries FOR EACH ROW EXECUTE FUNCTION unrelated_fn()');

    expect(sync()->diff())->toBe([])
        ->and(describeChanges(sync()->obsolete()))->toBe(['drop model_search_keys_stale_idx', 'drop model_search_gone_fn']);

    // apply() (and so the migration listener) only creates and replaces
    sync()->apply();
    event(new MigrationsEnded('up'));
    expect(triggerNames())->toContain('model_search_countries_ins')
        ->and(indexExists('model_search_keys_stale_idx'))->toBeTrue();

    // --check reports them as pending and still succeeds
    expect(Artisan::call('model-search:sync', ['--check' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('prune pending: drop triggers model_search_gone_fn')->toContain('prune pending: drop index model_search_keys_stale_idx');

    $this->artisan('model-search:sync', ['--prune' => true, '--dry-run' => true])->expectsOutputToContain('DROP FUNCTION')->assertSuccessful();
    expect(triggerNames())->toContain('model_search_countries_ins');

    $this->artisan('model-search:sync', ['--prune' => true])->assertSuccessful();

    expect(triggerNames())->not->toContain('model_search_countries_ins')
        ->and(indexExists('model_search_keys_stale_idx'))->toBeFalse()
        ->and(DB::table('pg_trigger')->where('tgname', 'unrelated_trg')->exists())->toBeTrue();

    DB::statement('DROP TRIGGER unrelated_trg ON countries');
    DB::statement('DROP FUNCTION unrelated_fn()');
});

it('builds a changed index beside the old one and removes the old one only on --prune', function (): void {
    $old = indexName('keys_key');

    // "The next release": the same definitions, one of them partitioned.
    ConfigurableOrderSearch::$partition = 'tenant_id';
    ConfigurableOrderSearch::$fields = [SearchField::key('signature'), SearchField::name('customer_name')];
    $registry = new ModelSearchRegistry;

    foreach ([ConfigurableOrderSearch::class, ParcelSearch::class, ProductSearch::class, InvoiceSearch::class, CustomerSearch::class] as $definition) {
        $registry->register($definition);
    }

    $next = new SchemaSync($registry);
    $new = array_values(array_filter($next->plan()->indexes, static fn ($spec): bool => $spec->family === 'keys_key'))[0]->name;
    expect($new)->not->toBe($old);

    try {
        $next->apply();

        expect(indexExists($old))->toBeTrue()
            ->and(indexExists($new))->toBeTrue()
            ->and($next->diff())->toBe([])
            ->and(describeChanges(array_values(array_filter($next->obsolete(), static fn ($change): bool => $change->kind === 'index'))))->toContain('drop '.$old);

        // back on the old plan, the new index is the one nobody plans: listed, kept, pruned on request
        ConfigurableOrderSearch::reset();
        sync()->apply();
        expect(Artisan::call('model-search:sync', ['--check' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('prune pending: drop index '.$new);
        expect(indexExists($new))->toBeTrue();

        sync()->apply(prune: true);

        expect(indexExists($new))->toBeFalse()
            ->and(indexExists($old))->toBeTrue();
    } finally {
        ConfigurableOrderSearch::reset();
    }
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
    DB::statement('DROP INDEX '.indexName('keys_key'));

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

it('installs what it can when a table is locked, then reports what is left', function (): void {
    DB::statement('DROP TRIGGER model_search_orders_ins ON orders');
    DB::statement('DROP TRIGGER model_search_addresses_ins ON addresses');
    DB::statement('DROP INDEX '.indexName('keys_key'));

    config(['database.connections.second' => config('database.connections.pgsql')]);
    $other = DB::connection('second');
    $other->beginTransaction();
    $other->getPdo()->exec('LOCK TABLE addresses IN ACCESS EXCLUSIVE MODE');

    try {
        $impatient = new SchemaSync(app(ModelSearchRegistry::class), null, '100ms', 1, 10);

        try {
            $impatient->apply();
            $this->fail('A locked table must make the sync report an incomplete run.');
        } catch (SchemaSyncIncomplete $exception) {
            expect($exception->getMessage())->toContain('addresses')->toContain('model-search:sync')
                ->and($exception->getMessage())->not->toContain('orders');
        }
    } finally {
        $other->rollBack();
    }

    expect(triggerNames())->toContain('model_search_orders_ins')->not->toContain('model_search_addresses_ins')
        ->and(indexExists(indexName('keys_key')))->toBeTrue();

    sync()->apply();
    expect(triggerNames())->toContain('model_search_addresses_ins');
});

it('does not run two syncs at once', function (): void {
    config(['database.connections.second' => config('database.connections.pgsql')]);
    $other = DB::connection('second');
    $other->select('SELECT pg_advisory_lock(hashtext(?))', ['model-search-sync:model_search_']);
    DB::statement('DROP TRIGGER model_search_orders_ins ON orders');

    try {
        $outcome = sync()->apply();
    } finally {
        $other->select('SELECT pg_advisory_unlock(hashtext(?))', ['model-search-sync:model_search_']);
    }

    expect($outcome->skipped)->toBe('another sync is running')
        ->and(triggerNames())->not->toContain('model_search_orders_ins');
});

it('drops an interrupted index build and reports it instead of stamping an invalid index', function (): void {
    $name = indexName('keys_key');
    DB::statement('DROP INDEX '.$name);

    config(['database.connections.second' => config('database.connections.pgsql')]);
    $other = DB::connection('second');
    $other->beginTransaction();
    $other->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $other->select('SELECT count(*) FROM invoices');

    try {
        $impatient = new SchemaSync(app(ModelSearchRegistry::class), null, '2s', 1, 10, '300ms');

        try {
            $impatient->apply();
            $this->fail('A build that waits for an old snapshot past its lock timeout must be reported.');
        } catch (SchemaSyncIncomplete $exception) {
            expect($exception->getMessage())->toContain($name);
        }

        $invalid = DB::selectOne("SELECT count(*) AS n FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE NOT i.indisvalid AND starts_with(c.relname, 'model_search_')");

        expect($invalid->n)->toBe(0)
            ->and(indexExists($name))->toBeFalse();
    } finally {
        $other->rollBack();
    }

    sync()->apply();

    $row = DB::selectOne('SELECT i.indisvalid AS valid, obj_description(i.indexrelid, \'pg_class\') AS comment FROM pg_index i WHERE i.indexrelid = to_regclass(?)', [$name]);

    expect($row->valid)->toBeTrue()
        ->and($row->comment)->toStartWith('model-search:')
        ->and(sync()->diff())->toBe([]);
});

it('does not let a failing advisory unlock hide the original exception', function (): void {
    config(['database.connections.second' => config('database.connections.pgsql')]);
    $other = DB::connection('second');
    $other->beginTransaction();
    $other->getPdo()->exec('DROP INDEX '.indexName('keys_key'));
    $other->beforeExecuting(static function (string $query): void {
        if (str_contains($query, 'pg_advisory_unlock')) {
            throw new RuntimeException('simulated 25P02 on unlock');
        }
    });

    try {
        expect(static fn () => (new SchemaSync(app(ModelSearchRegistry::class), 'second'))->apply())
            ->toThrow(LogicException::class, 'CONCURRENTLY');
    } finally {
        $other->rollBack();
    }
});

it('anchors the projection schema on the changes table, whatever the search path says', function (): void {
    DB::statement('CREATE SCHEMA IF NOT EXISTS model_search_decoy');
    sync()->plan();
    DB::statement('SET search_path = model_search_decoy, public');

    try {
        $schema = sync()->plan()->schema;
        $diff = sync()->diff();
    } finally {
        DB::statement('SET search_path = public');
        DB::statement('DROP SCHEMA model_search_decoy');
    }

    expect($schema)->toBe('public')
        ->and($diff)->toBe([]);
});

it('fixes the search path once per inspection pass', function (): void {
    $fixed = 0;
    DB::listen(static function ($query) use (&$fixed): void {
        if (str_contains($query->sql, "set_config('search_path', 'pg_catalog'")) {
            $fixed++;
        }
    });

    sync()->diff();

    expect($fixed)->toBe(1);
});
