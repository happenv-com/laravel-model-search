<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Facades\ModelSearches;
use Happenv\ModelSearch\Listeners\SyncAfterMigrations;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Query\SearchResult;
use Happenv\ModelSearch\Query\SearchResults;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\CustomerSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\InvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\MarketOrderSearchExtension;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ParcelSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ProductSearch;
use Happenv\ModelSearch\Tests\TruncationTestCase;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;

uses(TruncationTestCase::class);

/**
 * The whole life of a searchable type, on committed data and through the public entry points only:
 * register, migrate (sync and install), write, search while the index is building, drain, search
 * the finished index (palette with a `via` parent and a reveal rule, narrower scopes, `apply()`,
 * `similar()`, `candidates()`), then update and delete.
 */
afterEach(function (): void {
    // Hand the database back with the fixture definitions: drop what this test's definition planned.
    $registry = new ModelSearchRegistry;

    foreach ([OrderSearch::class, ParcelSearch::class, ProductSearch::class, InvoiceSearch::class, CustomerSearch::class] as $definition) {
        $registry->register($definition);
    }

    $registry->extend(MarketOrderSearchExtension::class);
    app()->instance(ModelSearchRegistry::class, $registry);
    DB::table(TableNames::forConnection()->state())->delete();
    app(SchemaSync::class)->apply(prune: true);
    ConfigurableOrderSearch::reset();
});

function slfViewer(int $id): User
{
    $viewer = new User;
    $viewer->id = $id;

    return $viewer;
}

/**
 * @return list<string>
 */
function slfSignatures(SearchResults $results): array
{
    $signatures = array_map(static fn (SearchResult $result): string => $result->model->signature, iterator_to_array($results));
    sort($signatures);

    return $signatures;
}

it('runs from registration to deletion', function (): void {
    ConfigurableOrderSearch::$fields = [
        SearchField::key('signature'),
        SearchField::key('customer_email')->revealUsing(static fn ($order, $viewer): bool => $viewer?->getAuthIdentifier() === 1),
        SearchField::name('customer_name'),
    ];
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);
    $registry->register(InvoiceSearch::class);
    app()->instance(ModelSearchRegistry::class, $registry);
    DB::table(TableNames::forConnection()->state())->delete();

    // register -> migrate: the listener syncs the triggers and indexes and installs the types.
    app(SyncAfterMigrations::class)->handle(new MigrationsEnded('up', []));

    expect(DB::table(TableNames::forConnection()->state())->pluck('subject_type')->sort()->values()->all())->toBe(['invoice', 'order'])
        ->and(ModelSearches::isReady(Order::class))->toBeFalse()
        ->and(ModelSearches::isRebuilding(Order::class))->toBeTrue();

    // Eloquent writes: the triggers fill the change log.
    $ann = Order::create(['signature' => 'ORDER-100', 'customer_email' => 'ann@example.com', 'customer_name' => 'Ann Kowalska', 'state' => 'unshipped']);
    $beata = Order::create(['signature' => 'ORDER-200', 'customer_name' => 'Beata Nowak', 'state' => 'shipped']);
    $annInvoice = Invoice::create(['number' => 'FV-1', 'order_id' => $ann->id]);
    Invoice::create(['number' => 'FV-2', 'order_id' => $beata->id]);

    // Search before the drain: served from the source tables, and says the index is building.
    $early = ConfigurableOrderSearch::query('ORDER-100')->within(Order::query())->get();

    expect(slfSignatures($early))->toBe(['ORDER-100'])
        ->and($early->indexBuilding)->toBeTrue();

    // Drain: the projection is built and the type becomes ready.
    $indexer = new SearchIndexer($registry, new IndexState);
    $indexer->drainNow();

    expect(ModelSearches::isReady(Order::class))->toBeTrue()
        ->and(ModelSearches::isRebuilding(Order::class))->toBeFalse();

    // Palette: the invoice is found through its order, and only through an order in the order query's scope.
    $palette = SearchQuery::palette(
        ConfigurableOrderSearch::query('ORDER-')->within(Order::query()->where('state', 'unshipped'))->forUser(slfViewer(1)),
        InvoiceSearch::query('ORDER-')->within(Invoice::query())->forUser(slfViewer(1)),
    );
    $invoice = iterator_to_array($palette['invoice'])[0];

    expect($palette['order']->indexBuilding)->toBeFalse()
        ->and(slfSignatures($palette['order']))->toBe(['ORDER-100'])
        ->and(count($palette['invoice']))->toBe(1)
        ->and($invoice->model->number)->toBe('FV-1')
        ->and($invoice->match->via)->toBe('order');

    // Reveal: the matched e-mail is shown to the viewer who may see it, and withheld from another.
    $revealed = iterator_to_array(ConfigurableOrderSearch::query('ann@example.com')->within(Order::query())->forUser(slfViewer(1))->get())[0]->match;
    $withheld = iterator_to_array(ConfigurableOrderSearch::query('ann@example.com')->within(Order::query())->forUser(slfViewer(2))->get())[0]->match;

    expect($revealed->kind)->toBe('customer_email')
        ->and($revealed->value)->toBe('ann@example.com')
        ->and($withheld->kind)->toBe('customer_email')
        ->and($withheld->value)->toBeNull();

    // A narrower within() sees fewer records.
    $broad = ConfigurableOrderSearch::query('ORDER-')->within(Order::query())->withoutStages()->get();
    $narrow = ConfigurableOrderSearch::query('ORDER-')->within(Order::query()->where('state', 'shipped'))->withoutStages()->get();

    expect(slfSignatures($broad))->toBe(['ORDER-100', 'ORDER-200'])
        ->and(slfSignatures($narrow))->toBe(['ORDER-200']);

    // apply(): the table query (and the within() scope) is narrowed, and its models carry nothing of the search.
    $applied = ConfigurableOrderSearch::query('ORDER-')->within(Order::query())->withoutStages()->apply(Order::query())->orderBy('signature')->get();
    $appliedNarrow = ConfigurableOrderSearch::query('ORDER-')->within(Order::query()->where('state', 'shipped'))->withoutStages()->apply(Order::query())->get();

    expect($applied->pluck('signature')->all())->toBe(['ORDER-100', 'ORDER-200'])
        ->and(array_keys($applied->first()->getAttributes()))->not->toContain('search_id')
        ->and($appliedNarrow->pluck('signature')->all())->toBe(['ORDER-200']);

    // similar(): a typo in a name still finds the record.
    expect(slfSignatures(ConfigurableOrderSearch::query('Kowalsky')->within(Order::query())->similar()))->toBe(['ORDER-100']);

    // candidates(): the raw rows of the index.
    $candidates = ConfigurableOrderSearch::query('ORDER-100')->withoutScope('test')->candidates();

    expect($candidates->where('type', 'order')->pluck('id')->all())->toBe([$ann->id]);

    // Update and drain: the index follows the row.
    $ann->update(['signature' => 'ORDER-111']);
    $indexer->drainNow();
    $find = static fn (string $phrase): array => slfSignatures(ConfigurableOrderSearch::query($phrase)->within(Order::query())->withoutStages()->get());

    expect($find('ORDER-111'))->toBe(['ORDER-111'])
        ->and($find('ORDER-100'))->toBe([]);

    // Delete and drain: nothing of the record is left in the index.
    $annInvoice->delete();
    $ann->delete();
    $indexer->drainNow();

    expect($find('ORDER-111'))->toBe([])
        ->and(DB::table(TableNames::forConnection()->keys())->where('subject_type', 'order')->where('subject_id', $ann->id)->count())->toBe(0)
        ->and(DB::table(TableNames::forConnection()->entries())->where('subject_type', 'order')->where('subject_id', $ann->id)->count())->toBe(0);
});
