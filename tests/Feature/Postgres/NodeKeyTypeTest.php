<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Postgres\Doctor\Doctor;
use Happenv\ModelSearch\Postgres\Doctor\Finding;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Indexing\PoisonRecords;
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Postgres\Schema\NodeKey;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

covers(NodeKey::class);

/*
 * The change log may hold node ids as text (default), uuid or bigint, as `model-search.node_key_type` says; the triggers,
 * the requeue of failures and the spill of roots write them in that type. The suite runs in text mode, so each test
 * here retypes the empty tables and reinstalls the triggers inside its own transaction, which rolls both back.
 */
beforeEach(function (): void {
    config(['model-search.driver' => 'postgres']);
});

afterEach(fn () => ConfigurableOrderSearch::reset());

/**
 * Both key columns typed as uuid, the registry holding only the given definitions, and their triggers reinstalled to
 * write uuid nodes.
 *
 * @param  list<class-string>  $definitions
 */
function nktUuid(array $definitions): SearchIndexer
{
    config(['model-search.subject_key_type' => 'uuid', 'model-search.node_key_type' => 'uuid']);
    $names = TableNames::forConnection();

    foreach ([[$names->entries(), 'subject_id'], [$names->keys(), 'subject_id'], [$names->failures(), 'subject_id'], [$names->changes(), 'node_id']] as [$table, $column]) {
        // The tables are empty: a typed NULL converts from any old type (there is no cast from bigint to uuid).
        DB::statement(sprintf('ALTER TABLE %1$s ALTER COLUMN "%2$s" TYPE uuid USING NULL::uuid', $names->quoted($table), $column));
    }

    $registry = new ModelSearchRegistry;

    foreach ($definitions as $definition) {
        $registry->register($definition);
    }

    app()->instance(ModelSearchRegistry::class, $registry);
    app()->forgetInstance(SchemaSync::class);
    app(SchemaSync::class)->apply();
    DB::table($names->state())->delete();
    $state = new IndexState;

    foreach ($registry->all() as $definition) {
        $state->recordInstall($definition);
    }

    return new SearchIndexer($registry, $state);
}

function nktAddress(string $phone): string
{
    $id = (string) Str::uuid();
    DB::table('addresses')->insert(['id' => $id, 'phone' => $phone]);

    return $id;
}

describe('uuid node ids', function (): void {
    it('logs uuid nodes from the triggers and finds the change after the drain', function (): void {
        $indexer = nktUuid([OrderSearch::class]);
        $order = Order::create(['signature' => 'NODE-1', 'billing_address_id' => nktAddress('600100200')]);

        expect(DB::table(TableNames::forConnection()->changes())->where('node_id', $order->id)->exists())->toBeTrue();

        $indexer->drainNow();

        expect(msSignatures(OrderSearch::query('600100200')->within(Order::query())->get()))->toBe(['NODE-1']);
    });

    it('spills the roots of a shared address back to the log as uuid nodes', function (): void {
        $indexer = nktUuid([OrderSearch::class]);
        $address = nktAddress('111111111');
        $orders = array_map(static fn (int $n): Order => Order::create(['signature' => 'SPILL-'.$n, 'billing_address_id' => $address]), range(1, 3));
        $indexer->drainNow();

        config(['model-search.drain.fan_out_limit' => 2]);
        DB::table('addresses')->where('id', $address)->update(['phone' => '222222222']);
        $stats = $indexer->drain(0);

        expect($stats->spilledRoots)->toBe(3)
            ->and(DB::table(TableNames::forConnection()->changes())->pluck('node_id')->sort()->values()->all())
            ->toBe(collect($orders)->pluck('id')->sort()->values()->all());

        $indexer->drainNow();

        expect(msSignatures(OrderSearch::query('222222222')->within(Order::query())->limit(10)->get()))->toHaveCount(3);
    });

    it('requeues a parked failure as a uuid node', function (): void {
        nktUuid([OrderSearch::class]);
        $order = Order::create(['signature' => 'POISON-1']);
        DB::table(TableNames::forConnection()->changes())->delete();
        $poison = new PoisonRecords(TableNames::forConnection());

        $poison->record(DB::connection(), 'order', $order->id, new RuntimeException('broken'));

        expect($poison->requeue(DB::connection(), ['order'], [$order->id]))->toBe(1)
            ->and(DB::table(TableNames::forConnection()->changes())->value('node_id'))->toBe($order->id);
    });

    it('refuses a definition that watches a key of another type', function (): void {
        config(['model-search.subject_key_type' => 'uuid', 'model-search.node_key_type' => 'uuid']);
        ConfigurableOrderSearch::$fields = [SearchField::key('tags.name')];
        $registry = new ModelSearchRegistry;
        $registry->register(ConfigurableOrderSearch::class);

        expect(static fn () => $registry->all())->toThrow(InvalidSearchDefinition::class, 'but the change log stores node ids as uuid');
    });
});

it('refuses a typed node key that differs from the subject key type', function (): void {
    config(['model-search.subject_key_type' => 'text', 'model-search.node_key_type' => 'uuid']);

    expect(static fn () => NodeKey::assertConsistentWithSubjects())->toThrow(InvalidSearchDefinition::class, 'must be the subject key type');
});

it('creates the change log with the configured node key type', function (): void {
    config(['model-search.subject_key_type' => 'uuid', 'model-search.node_key_type' => 'uuid', 'model-search.table_prefix' => 'nkt_']);

    (require __DIR__.'/../../../database/migrations/2026_11_05_000000_create_model_search_tables.php')->up();

    $type = DB::selectOne("SELECT format_type(atttypid, NULL) AS type FROM pg_attribute WHERE attrelid = to_regclass('nkt_changes') AND attname = 'node_id'");

    expect($type->type)->toBe('uuid');
});

it('the doctor names a node id column whose type differs from the setting', function (): void {
    config(['model-search.subject_key_type' => 'uuid', 'model-search.node_key_type' => 'uuid']);
    app()->instance(ModelSearchRegistry::class, tap(new ModelSearchRegistry, static fn (ModelSearchRegistry $registry) => $registry->register(OrderSearch::class)));

    $findings = array_values(array_filter(app(Doctor::class)->inspect(), static fn (Finding $finding): bool => $finding->code === 'node-key-type'));

    expect(array_map(static fn (Finding $finding): ?string => $finding->subject, $findings))->toBe([TableNames::forConnection()->changes()])
        ->and($findings[0]->message)->toContain('node_id has the type text');
});

it('uninstall removes the triggers from the watched tables', function (): void {
    $count = static fn (): int => (int) DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = 'orders'::regclass")->n;
    expect($count())->toBeGreaterThan(0);

    $this->artisan('model-search:uninstall')->assertSuccessful();

    expect($count())->toBe(0);
});
