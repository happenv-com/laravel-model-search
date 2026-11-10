<?php

declare(strict_types=1);

use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Postgres\Doctor\Doctor;
use Happenv\ModelSearch\Postgres\Doctor\Finding;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Indexing\PoisonRecords;
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Postgres\Schema\SubjectKey;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Customer;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\CustomerSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\InvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Support\Facades\DB;

covers(SubjectKey::class);

/*
 * The index tables may hold subject ids as bigint (Laravel's `id()`), uuid or text, as `model-search.subject_key_type`
 * says; the package's SQL binds, casts and orders them in that type. The suite runs in text mode (its fixtures mix key
 * types), so each test here retypes the empty tables inside its own transaction, which rolls the change back.
 */
beforeEach(function (): void {
    config(['model-search.driver' => 'postgres']);
});

function sktRetype(string $type): void
{
    config(['model-search.subject_key_type' => $type]);
    $names = TableNames::forConnection();

    foreach ([$names->entries(), $names->keys(), $names->failures()] as $table) {
        // The tables are empty: a typed NULL converts from any old type (there is no cast from bigint to uuid).
        DB::statement(sprintf('ALTER TABLE %1$s ALTER COLUMN "subject_id" TYPE %2$s USING NULL::%2$s', $names->quoted($table), $type));
    }
}

/**
 * A registry with only the given definitions, installed and drained.
 *
 * @param  list<class-string>  $definitions
 */
function sktIndex(array $definitions): SearchIndexer
{
    $registry = new ModelSearchRegistry;

    foreach ($definitions as $definition) {
        $registry->register($definition);
    }

    app()->instance(ModelSearchRegistry::class, $registry);
    DB::table(TableNames::forConnection()->state())->delete();
    $state = new IndexState;

    foreach ($registry->all() as $definition) {
        $state->recordInstall($definition);
    }

    $indexer = new SearchIndexer($registry, $state);
    $indexer->drainNow();

    return $indexer;
}

/**
 * @return list<string>
 */
function sktColumnTypes(string $table): array
{
    return array_map(
        static fn (object $row): string => $row->attname.':'.$row->type,
        DB::select('SELECT a.attname, format_type(a.atttypid, a.atttypmod) AS type FROM pg_attribute AS a WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum', [$table]),
    );
}

describe('uuid subject ids', function (): void {
    it('indexes, finds, narrows and forgets a uuid-keyed record', function (): void {
        sktRetype('uuid');
        $kept = Order::create(['signature' => 'UUID-1', 'customer_email' => 'jan@example.com', 'customer_name' => 'Jan Kowalski']);
        $removed = Order::create(['signature' => 'UUID-2', 'customer_email' => 'anna@example.com']);
        $indexer = sktIndex([OrderSearch::class]);
        $keys = TableNames::forConnection()->keys();

        expect(DB::table($keys)->where('subject_id', $kept->id)->count())->toBeGreaterThan(0)
            ->and(msSignatures(OrderSearch::query('jan@example.com')->within(Order::query())->get()))->toBe(['UUID-1'])
            ->and(msSignatures(OrderSearch::query('kowal')->within(Order::query())->get()))->toBe(['UUID-1'])
            ->and(OrderSearch::query('UUID')->within(Order::query())->apply(Order::query())->orderBy('signature')->pluck('signature')->all())->toBe(['UUID-1', 'UUID-2']);

        $removed->delete();
        $indexer->drainNow();

        expect(DB::table($keys)->where('subject_id', $removed->id)->count())->toBe(0)
            ->and(DB::table(TableNames::forConnection()->entries())->where('subject_id', $removed->id)->count())->toBe(0);
    });

    it('removes entries of vanished records when a rebuild sweeps the type', function (): void {
        sktRetype('uuid');
        $order = Order::create(['signature' => 'SWEEP-1']);
        $indexer = sktIndex([OrderSearch::class]);
        DB::table('orders')->where('id', $order->id)->delete();
        DB::table(TableNames::forConnection()->changes())->delete();

        $indexer->rebuild(['order']);
        $indexer->drainNow();

        expect(DB::table(TableNames::forConnection()->entries())->where('subject_type', 'order')->count())->toBe(0);
    });

    it('parks, requeues and clears a failed record', function (): void {
        sktRetype('uuid');
        $order = Order::create(['signature' => 'POISON-1']);
        sktIndex([OrderSearch::class]);
        $poison = new PoisonRecords(TableNames::forConnection());
        $db = DB::connection();

        $poison->record($db, 'order', $order->id, new RuntimeException('broken'));
        $requeued = $poison->requeue($db, ['order'], [$order->id]);
        $poison->clear($db, 'order', [$order->id]);

        expect($requeued)->toBe(1)
            ->and(DB::table(TableNames::forConnection()->changes())->where('node_id', $order->id)->exists())->toBeTrue()
            ->and(DB::table(TableNames::forConnection()->failures())->count())->toBe(0);
    });

    it('finds a child through the number of its parent', function (): void {
        sktRetype('uuid');
        $order = Order::create(['signature' => 'VIA-1']);
        Invoice::create(['number' => 'FV/1', 'order_id' => $order->id]);
        sktIndex([OrderSearch::class, InvoiceSearch::class]);

        $query = static fn () => InvoiceSearch::query('VIA-1')->within(Invoice::query(), [Order::class => Order::query()]);

        expect(array_map(static fn ($result): string => $result->model->number, iterator_to_array($query()->get())))->toBe(['FV/1'])
            ->and($query()->apply(Invoice::query())->pluck('number')->all())->toBe(['FV/1']);
    });
});

describe('bigint subject ids', function (): void {
    it('indexes and finds a bigint-keyed record', function (): void {
        sktRetype('bigint');
        $customer = Customer::create(['name' => 'Ewa Malinowska', 'email' => 'ewa@example.com']);
        sktIndex([CustomerSearch::class]);

        $found = CustomerSearch::query('ewa@example.com')->within(Customer::query())->get();

        expect(array_map(static fn ($result): int => $result->model->getKey(), iterator_to_array($found)))->toBe([$customer->id])
            ->and(CustomerSearch::query('malin')->within(Customer::query())->apply(Customer::query())->pluck('id')->all())->toBe([$customer->id]);
    });

    it('refuses a model whose key does not fit', function (): void {
        sktRetype('bigint');
        $registry = new ModelSearchRegistry;
        $registry->register(OrderSearch::class);

        expect(static fn () => $registry->all())->toThrow(InvalidSearchDefinition::class, 'stores subject ids as bigint');
    });
});

it('refuses a subject key type it does not know', function (): void {
    config(['model-search.subject_key_type' => 'varchar']);

    expect(static fn (): string => SubjectKey::type())->toThrow(InvalidSearchDefinition::class, 'must be one of bigint, uuid, text');
});

it('creates the tables with the configured subject key type and time-zone-aware moments', function (): void {
    config(['model-search.subject_key_type' => 'uuid', 'model-search.table_prefix' => 'skt_']);

    (require __DIR__.'/../../../database/migrations/2026_11_05_000000_create_model_search_tables.php')->up();

    expect(sktColumnTypes('skt_entries'))->toContain('subject_id:uuid', 'sort_at:timestamp(6) with time zone')
        ->and(sktColumnTypes('skt_keys'))->toContain('subject_id:uuid')
        ->and(sktColumnTypes('skt_failures'))->toContain('subject_id:uuid')
        ->and(sktColumnTypes('skt_changes'))->toContain('node_id:text', 'changed_at:timestamp(6) with time zone');
});

it('the doctor names subject id columns whose type differs from the setting', function (): void {
    config(['model-search.subject_key_type' => 'uuid']);
    app()->instance(ModelSearchRegistry::class, tap(new ModelSearchRegistry, static fn (ModelSearchRegistry $registry) => $registry->register(OrderSearch::class)));

    $findings = array_values(array_filter(app(Doctor::class)->inspect(), static fn (Finding $finding): bool => $finding->code === 'subject-key-type'));

    expect(array_map(static fn (Finding $finding): ?string => $finding->subject, $findings))->toBe([
        TableNames::forConnection()->entries(),
        TableNames::forConnection()->keys(),
        TableNames::forConnection()->failures(),
    ])->and($findings[0]->message)->toContain('has the type text');
});
