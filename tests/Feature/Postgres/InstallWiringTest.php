<?php

declare(strict_types=1);

use Happenv\ModelSearch\Exceptions\InstallRefused;
use Happenv\ModelSearch\Exceptions\SchemaSyncIncomplete;
use Happenv\ModelSearch\Listeners\SyncAfterMigrations;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\TruncationTestCase;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(TruncationTestCase::class);

covers(SchemaSync::class);

function iwState(): string
{
    return TableNames::forConnection()->state();
}

function iwSync(): SchemaSync
{
    return app(SchemaSync::class);
}

beforeEach(function (): void {
    DB::table(iwState())->delete();
});

afterEach(function (): void {
    DB::purge('second');
    DB::table(iwState())->delete();
    iwSync()->apply(prune: true);
});

it('installs every registered type once its triggers and indexes are in place', function (): void {
    $outcome = iwSync()->apply();

    expect($outcome->installs)->toBe([
        'customer' => 'installed',
        'invoice' => 'installed',
        'order' => 'installed',
        'parcel' => 'installed',
        'product' => 'installed',
    ])->and(DB::table(iwState())->count())->toBe(5)
        ->and(DB::table(iwState())->where('subject_type', 'order')->value('rebuild_cursor'))->toBe('');

    expect(iwSync()->apply()->installs['order'])->toBe('unchanged');
});

it('installs nothing on a connection none of the definitions watch', function (): void {
    config(['database.connections.second' => config('database.connections.pgsql')]);

    $outcome = (new SchemaSync(app(ModelSearchRegistry::class), 'second'))->apply();

    expect($outcome->installs)->toBe([])
        ->and(DB::table(iwState())->count())->toBe(0);
});

it('migrate, drain, ready: the listener leaves a searchable index', function (): void {
    $id = (string) Str::uuid();
    DB::table('orders')->insert(['id' => $id, 'signature' => 'MIG-1', 'customer_name' => 'Ann Kowalska', 'state' => 'unshipped']);

    app(SyncAfterMigrations::class)->handle(new MigrationsEnded('up', []));
    app(SearchIndexer::class)->drainNow();

    expect(app(IndexState::class)->isReady('order'))->toBeTrue()
        ->and(DB::table(TableNames::forConnection()->keys())->where('subject_id', $id)->where('kind', 'name_word')->pluck('key')->sort()->values()->all())->toBe(['ann', 'kowalska']);
});

it('does not install a type whose table stayed locked, and installs the rest', function (): void {
    iwSync()->apply();
    DB::table(iwState())->delete();
    DB::statement('DROP TRIGGER model_search_parcels_ins ON parcels');

    config(['database.connections.second' => config('database.connections.pgsql')]);
    $other = DB::connection('second');
    $other->beginTransaction();
    $other->getPdo()->exec('LOCK TABLE parcels IN ACCESS EXCLUSIVE MODE');

    try {
        $impatient = new SchemaSync(app(ModelSearchRegistry::class), null, '100ms', 1, 10);

        try {
            $impatient->apply();
            $this->fail('A locked table must make the sync report an incomplete run.');
        } catch (SchemaSyncIncomplete) {
        }
    } finally {
        $other->rollBack();
    }

    $installed = DB::table(iwState())->pluck('subject_type')->sort()->values()->all();

    expect($installed)->toBe(['customer', 'invoice', 'order', 'product']);
});

it('refuses a downgrade without stopping the other types', function (): void {
    DB::table(iwState())->insert(['subject_type' => 'order', 'definition_fingerprint' => 'other', 'definition_version' => 5, 'installed_at' => now()]);

    $outcome = iwSync()->apply();

    expect($outcome->installs['order'])->toBe('refused')
        ->and($outcome->installs['parcel'])->toBe('installed')
        ->and($outcome->refusals)->toHaveCount(1)
        ->and($outcome->refusals[0]->isDowngrade)->toBeTrue();
});

it('the sync command fails on a refusal, the migration listener only warns about a downgrade', function (): void {
    DB::table(iwState())->insert(['subject_type' => 'order', 'definition_fingerprint' => 'other', 'definition_version' => 5, 'installed_at' => now()]);
    Log::spy();

    expect(Artisan::call('model-search:sync'))->toBe(1)
        ->and(Artisan::output())->toContain('older');

    app(SyncAfterMigrations::class)->handle(new MigrationsEnded('up', []));

    Log::shouldHaveReceived('warning')->once();
});

it('a foreign fingerprint at the same version fails the listener too', function (): void {
    DB::table(iwState())->insert(['subject_type' => 'order', 'definition_fingerprint' => 'other', 'definition_version' => 1, 'installed_at' => now()]);

    expect(fn () => app(SyncAfterMigrations::class)->handle(new MigrationsEnded('up', [])))->toThrow(InstallRefused::class, 'bump ModelSearch::version()')
        ->and(fn () => app(SyncAfterMigrations::class)->handle(new MigrationsEnded('up', [])))->toThrow(InstallRefused::class, 'name_words');
});

it('--check lists types that are not installed or whose fingerprint differs', function (): void {
    expect(Artisan::call('model-search:sync', ['--check' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('not installed');

    iwSync()->apply();

    expect(Artisan::call('model-search:sync', ['--check' => true]))->toBe(0);

    DB::table(iwState())->where('subject_type', 'order')->update(['definition_fingerprint' => 'changed']);

    expect(Artisan::call('model-search:sync', ['--check' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('fingerprint');
});

it('a new version of a definition is installed by the next sync and asks for a rebuild', function (): void {
    iwSync()->apply();
    DB::table(iwState())->update(['rebuild_cursor' => null, 'rebuild_started_at' => null, 'ready_at' => now()]);
    $before = (new IndexState)->find('order');

    ConfigurableOrderSearch::$definitionVersion = 2;
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);

    try {
        $outcome = (new SchemaSync($registry))->apply();
    } finally {
        ConfigurableOrderSearch::reset();
    }

    $after = (new IndexState)->find('order');

    expect($outcome->installs)->toBe(['order' => 'updated'])
        ->and($before['definition_version'])->toBe(1)
        ->and($after['definition_version'])->toBe(2)
        ->and($after['definition_fingerprint'])->not->toBe($before['definition_fingerprint'])
        ->and($after['rebuild_cursor'])->toBe('')
        ->and($after['ready_at'])->not->toBeNull();
});

it('hands the refusals to the incomplete-sync exception, and the migration listener logs them', function (): void {
    DB::table(iwState())->insert(['subject_type' => 'order', 'definition_fingerprint' => 'other', 'definition_version' => 5, 'installed_at' => now()]);
    iwSync()->apply();
    DB::statement('DROP TRIGGER model_search_parcels_ins ON parcels');

    config(['database.connections.second' => config('database.connections.pgsql')]);
    $other = DB::connection('second');
    $other->beginTransaction();
    $other->getPdo()->exec('LOCK TABLE parcels IN ACCESS EXCLUSIVE MODE');

    Log::spy();
    app()->instance(SchemaSync::class, new SchemaSync(app(ModelSearchRegistry::class), null, '100ms', 1, 10));

    try {
        try {
            (new SchemaSync(app(ModelSearchRegistry::class), null, '100ms', 1, 10))->apply();
            $this->fail('A locked table must make the sync report an incomplete run.');
        } catch (SchemaSyncIncomplete $incomplete) {
            expect($incomplete->refusals)->toHaveCount(1)
                ->and($incomplete->refusals[0]->isDowngrade)->toBeTrue();
        }

        expect(fn () => app(SyncAfterMigrations::class)->handle(new MigrationsEnded('up', [])))->toThrow(SchemaSyncIncomplete::class);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    } finally {
        $other->rollBack();
    }
});
