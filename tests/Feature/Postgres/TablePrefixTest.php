<?php

declare(strict_types=1);

use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Happenv\ModelSearch\Tests\TruncationTestCase;
use Illuminate\Support\Facades\DB;

uses(TruncationTestCase::class);

covers(SchemaSync::class);

/*
 * A host that gives its connection a table prefix (a tenant per prefix is the usual reason) owns tables named
 * `<prefix>orders` and `<prefix>model_search_keys`. The package must reach every one of them, in the migration's own
 * raw statements, in the triggers and in the drain, not only through the schema builder, which prefixes by itself.
 * The prefixed connection lives in a schema of its own, so that its functions do not replace the unprefixed ones.
 */
afterEach(function (): void {
    DB::setDefaultConnection('pgsql');
    DB::purge('prefixed');
    DB::statement('DROP SCHEMA IF EXISTS prefixed_test CASCADE');
});

it('creates, triggers and drains the projection on a connection with a table prefix', function (): void {
    DB::statement('DROP SCHEMA IF EXISTS prefixed_test CASCADE');
    DB::statement('CREATE SCHEMA prefixed_test');
    config(['database.connections.prefixed' => [...config('database.connections.pgsql'), 'prefix' => 'tenant_', 'search_path' => 'prefixed_test,public']]);
    DB::setDefaultConnection('prefixed');
    app()->instance(ModelSearchRegistry::class, tap(new ModelSearchRegistry, static fn (ModelSearchRegistry $registry) => $registry->register(OrderSearch::class)));
    app()->forgetInstance(SchemaSync::class);

    foreach (['Fixtures/Migrations/2026_11_05_000001_create_fixture_tables.php', '../database/migrations/2026_11_05_000000_create_model_search_tables.php'] as $file) {
        (require __DIR__.'/../../'.$file)->up();
    }

    $tuned = collect(DB::select("select relname, reloptions from pg_class where relname in ('tenant_model_search_entries', 'tenant_model_search_keys')"))->pluck('reloptions', 'relname')->all();

    expect($tuned)->toHaveCount(2)
        ->and($tuned['tenant_model_search_entries'])->toBeString()->toContain('autovacuum_vacuum_scale_factor=0.02')
        ->and($tuned['tenant_model_search_keys'])->toBeString()->toContain('autovacuum_analyze_scale_factor=0.01');

    app(SchemaSync::class)->apply();

    $triggers = array_column(DB::select("select tgname from pg_trigger where not tgisinternal and tgrelid = 'prefixed_test.tenant_orders'::regclass"), 'tgname');

    expect($triggers)->toContain('model_search_orders_ins', 'model_search_orders_upd', 'model_search_orders_del');

    Order::create(['signature' => 'PREFIX-1']);
    msIndex();

    expect(DB::table('model_search_keys')->where('kind', 'signature')->count())->toBeGreaterThan(0)
        ->and(msSignatures(OrderSearch::query('PREFIX')->within(Order::query())->get()))->toBe(['PREFIX-1']);
});
