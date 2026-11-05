<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Exceptions\PartitionMissing;
use Happenv\ModelSearch\Exceptions\SearchFailed;
use Happenv\ModelSearch\Exceptions\SearchScopeMissing;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Happenv\ModelSearch\Exceptions\UnsupportedQuery;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Postgres\Query\PostgresDriver;
use Happenv\ModelSearch\Postgres\Query\RoundOne;
use Happenv\ModelSearch\Postgres\Query\TypeRound;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Postgres\Session\SearchConnectionFactory;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Query\SearchResult;
use Happenv\ModelSearch\Query\SearchResults;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Address;
use Happenv\ModelSearch\Tests\Fixtures\Models\Customer;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Models\Parcel;
use Happenv\ModelSearch\Tests\Fixtures\Models\Product;
use Happenv\ModelSearch\Tests\Fixtures\Models\SecondaryCustomer;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\CustomerSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\InvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OwnerKeyInvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ParcelSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\PartitionedInvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ProductSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\SecondaryCustomerSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\StagedInvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Session\RecordingSearchConnectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;

covers(PostgresDriver::class, RoundOne::class);

beforeEach(function (): void {
    config(['model-search.driver' => 'postgres']);
});

afterEach(fn () => ConfigurableOrderSearch::reset());

/**
 * Brings the index up to date with the rows the test created. The types are installed when they
 * were not, so a test that swapped the registry gets its own definition indexed.
 */
function prIndex(): void
{
    $registry = app(ModelSearchRegistry::class);
    $state = new IndexState;

    foreach ($registry->all() as $definition) {
        if ($state->find($definition->morphAlias) === null) {
            $state->recordInstall($definition);
        }
    }

    (new SearchIndexer($registry, $state))->drainNow();
}

/**
 * Replaces the order definition with one the test configures, starting from a clean state table.
 *
 * @param  list<SearchField>  $fields
 */
function prUse(array $fields): void
{
    ConfigurableOrderSearch::$fields = $fields;
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);
    app()->instance(ModelSearchRegistry::class, $registry);
    DB::table(TableNames::forConnection()->state())->delete();
}

function prRecorder(): RecordingSearchConnectionFactory
{
    $factory = new RecordingSearchConnectionFactory;
    app()->instance(SearchConnectionFactory::class, $factory);

    return $factory;
}

/**
 * The statements that read the index: one per round, whatever the number of types.
 *
 * @return list<array{query: string, bindings: array<mixed>, time: float|null}>
 */
function prIndexStatements(RecordingSearchConnectionFactory $recorder): array
{
    return array_values(array_filter($recorder->log(), static fn (array $entry): bool => str_contains($entry['query'], 'model_search_keys')));
}

/**
 * Every statement a search ran, except the session's own settings lines.
 *
 * @return list<array{query: string, bindings: array<mixed>, time: float|null}>
 */
function prSearchStatements(RecordingSearchConnectionFactory $recorder): array
{
    return array_values(array_filter($recorder->log(), static fn (array $entry): bool => ! str_starts_with($entry['query'], 'SET LOCAL')
        && ! str_contains($entry['query'], 'current_setting(')
        && ! str_contains($entry['query'], 'pg_settings')
        && ! str_starts_with($entry['query'], 'SELECT set_config(')));
}

/**
 * @return list<string>
 */
function prSignatures(SearchResults $results): array
{
    return array_map(static fn (SearchResult $result): string => $result->model->signature, iterator_to_array($results));
}

function prOrders(string $phrase): SearchQuery
{
    return OrderSearch::query($phrase)->within(Order::query());
}

/**
 * @return list<string>
 */
function prSignaturesOf(SearchResults $results, string $attribute): array
{
    return array_map(static fn (SearchResult $result): string => (string) $result->model->{$attribute}, iterator_to_array($results));
}

/**
 * @return list<string>
 */
function prFind(string $phrase): array
{
    prIndex();

    return prSignatures(prOrders($phrase)->get());
}

describe('matching', function (): void {
    it('finds by exact, prefix, suffix and name prefix with the mode that matched', function (): void {
        prUse([
            SearchField::key('external_number'),
            SearchField::name('customer_name'),
        ]);
        Order::create(['signature' => 'S-1', 'external_number' => '302-4562151-0001', 'customer_name' => 'Jan Kowalski']);
        Order::create(['signature' => 'S-2', 'external_number' => '999-1111111-2222', 'customer_name' => 'Anna Nowak-Zielinska']);
        prIndex();
        $find = static fn (string $phrase): SearchResults => ConfigurableOrderSearch::query($phrase)->within(Order::query())->get();

        $exact = $find('302 4562151 0001');
        $prefix = $find('302-456');
        $suffix = $find('4562151-0001');
        $name = $find('kowal');
        $secondWord = $find('zieli');

        expect(prSignatures($exact))->toBe(['S-1'])
            ->and(iterator_to_array($exact)[0]->match->mode)->toBe(Mode::Exact)
            ->and(prSignatures($prefix))->toBe(['S-1'])
            ->and(iterator_to_array($prefix)[0]->match->mode)->toBe(Mode::Prefix)
            ->and(prSignatures($suffix))->toBe(['S-1'])
            ->and(iterator_to_array($suffix)[0]->match->mode)->toBe(Mode::Suffix)
            ->and(prSignatures($name))->toBe(['S-1'])
            ->and(iterator_to_array($name)[0]->match->mode)->toBe(Mode::Prefix)
            ->and(iterator_to_array($name)[0]->match->kind)->toBe('customer_name')
            ->and(iterator_to_array($name)[0]->match->value)->toBe('Jan Kowalski')
            ->and(prSignatures($secondWord))->toBe(['S-2']);
    });

    it('reads name words: every word of the phrase must start a word of the name', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        Order::create(['signature' => 'S-2', 'customer_name' => 'Jan Nowak']);

        expect(prFind('kowal jan'))->toBe(['S-1'])
            ->and(prFind('jan kowal'))->toBe(['S-1'])
            ->and(prFind('wal'))->toBe([]);
    });

    it('needs every word of a multi-word phrase', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        Order::create(['signature' => 'S-2', 'customer_name' => 'Kowalski Piotr']);

        expect(prFind('kowal jan'))->toBe(['S-1'])
            ->and(prFind('kowal pio'))->toBe(['S-2'])
            ->and(prFind('kowal zzz'))->toBe([]);
    });

    it('ranks an exact name word above a longer word that only starts with it', function (): void {
        Order::create(['signature' => 'S-prefix', 'customer_name' => 'Kowalska Anna']);
        Order::create(['signature' => 'S-word', 'customer_name' => 'Anna Kowal']);

        expect(prFind('kowal'))->toBe(['S-word', 'S-prefix']);
    });

    it('skips the name branches for an identifier-shaped phrase', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => '123456 Warehouse']);
        Order::create(['signature' => 'S-2', 'customer_name' => 'Plain', 'customer_email' => 'a@example.com']);
        prIndex();

        $statement = RoundOne::sql(['order' => ['definition' => app(ModelSearchRegistry::class)->for(Order::class), 'query' => prOrders('123456'), 'host' => prOrders('123456')->scope()]]);

        expect(prFind('123456'))->toBe([])
            ->and(prFind('a@example.com'))->toBe(['S-2'])
            ->and($statement['sql'] ?? '')->not->toContain("kind = 'name_word'");
    });

    it('finds a key through a relation path and ignores separators in the stored value', function (): void {
        $address = Address::create(['phone' => '+48 600-100-200']);
        Order::create(['signature' => 'S-1', 'billing_address_id' => $address->id]);
        Order::create(['signature' => 'S-2']);
        prIndex();

        $results = prOrders('48600100200')->get();

        expect(prSignatures($results))->toBe(['S-1'])
            ->and(iterator_to_array($results)[0]->match->kind)->toBe('phone')
            ->and(iterator_to_array($results)[0]->match->mode)->toBe(Mode::Exact);
    });

    it('ignores the stored case and the separators of a key', function (): void {
        Order::create(['signature' => 'OR-9173/A']);

        expect(prFind('or9173a'))->toBe(['OR-9173/A']);
    });

    it('uses the locale variants of the phrase', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Mueller GmbH']);
        prIndex();

        expect(prSignatures(prOrders('Müller')->get()))->toBe([])
            ->and(prSignatures(prOrders('Müller')->locale('de')->get()))->toBe(['S-1']);
    });

    it('reads every locale of a translatable name and skips deleted records', function (): void {
        Product::create(['sku' => 'P-1', 'name' => ['pl' => 'Sprzet domowy', 'en' => 'Household gear']]);
        Product::create(['sku' => 'P-2', 'name' => ['en' => 'Household tools']])->delete();
        prIndex();

        $results = ProductSearch::query('house')->within(Product::query())->get();

        expect(array_map(static fn (SearchResult $r): string => $r->model->sku, iterator_to_array($results)))->toBe(['P-1']);
    });

    it('treats like metacharacters as literal', function (): void {
        Order::create(['signature' => 'S-1', 'external_number' => 'ab%cd1234']);
        Order::create(['signature' => 'S-2', 'external_number' => 'abXcd1234']);
        Order::create(['signature' => 'S-3', 'customer_name' => '100% Cotton']);
        Order::create(['signature' => 'S-4', 'customer_name' => '100 Cotton']);
        Order::create(['signature' => 'S-5', 'external_number' => 'a_cd1234']);
        Order::create(['signature' => 'S-6', 'external_number' => 'abcd1234']);

        expect(prFind('ab%cd'))->toBe(['S-1'])
            ->and(prFind('ab%'))->toBe(['S-1'])
            ->and(prFind('%%%'))->toBe([])
            ->and(prFind('cot%'))->toBe([])
            ->and(prFind('a_cd'))->toBe(['S-5']);
    });

    it('respects only() and using()', function (): void {
        Order::create(['signature' => 'ABC-1', 'external_number' => 'ABC-1-EXT']);
        Order::create(['signature' => 'ZZZ-OTHER', 'external_number' => 'ABC-1']);
        Order::create(['signature' => 'Q-3', 'customer_name' => 'Abc Person']);
        prIndex();
        $find = static function (string $phrase, Closure $change): array {
            $names = prSignatures($change(prOrders($phrase))->get());
            sort($names);

            return $names;
        };

        expect($find('ABC', fn (SearchQuery $q) => $q))->toBe(['ABC-1', 'Q-3', 'ZZZ-OTHER'])
            ->and($find('ABC', fn (SearchQuery $q) => $q->only(['signature'])))->toBe(['ABC-1'])
            ->and($find('ABC', fn (SearchQuery $q) => $q->only(['external_number'])))->toBe(['ABC-1', 'ZZZ-OTHER'])
            ->and($find('ABC', fn (SearchQuery $q) => $q->only(['customer_name', 'shipping_name'])))->toBe(['Q-3'])
            ->and($find('ABC-1', fn (SearchQuery $q) => $q->using(Mode::Exact)))->toBe(['ABC-1', 'ZZZ-OTHER'])
            ->and($find('ABC-1', fn (SearchQuery $q) => $q->using(Mode::Exact)->only(['external_number'])))->toBe(['ZZZ-OTHER'])
            ->and($find('ABC', fn (SearchQuery $q) => $q->using(Mode::Exact)))->toBe([])
            ->and($find('person', fn (SearchQuery $q) => $q->using(Mode::Exact, Mode::Suffix)))->toBe([])
            ->and($find('person', fn (SearchQuery $q) => $q->using(Mode::Prefix)))->toBe(['Q-3']);
    });

    it('skips a mode when the phrase is below its minimum length', function (): void {
        prUse([
            SearchField::key('external_number')->matches(Mode::Exact, Mode::Prefix, Mode::Suffix),
            SearchField::name('customer_name'),
        ]);
        Order::create(['signature' => 'S-1', 'external_number' => 'ab12345678', 'customer_name' => 'Kowalski']);
        Order::create(['signature' => 'S-2', 'external_number' => 'ab']);
        prIndex();
        $find = static fn (string $phrase): array => prSignatures(ConfigurableOrderSearch::query($phrase)->within(Order::query())->get());

        expect($find('ab'))->toBe(['S-2'])
            ->and($find('ab1'))->toBe(['S-1'])
            ->and($find('678'))->toBe([])
            ->and($find('5678'))->toBe(['S-1'])
            ->and($find('ko'))->toBe([])
            ->and($find('kow'))->toBe(['S-1']);
    });

    it('measures the name minimum on the longest word of the phrase', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jo Li Kowalski']);

        expect(prFind('jo li kow'))->toBe(['S-1'])
            ->and(prFind('jo li'))->toBe([]);
    });

    it('returns nothing and runs nothing for a phrase that normalizes to nothing', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Kowalski']);
        prIndex();
        $recorder = prRecorder();

        expect(prSignatures(prOrders('日本語')->get()))->toBe([])
            ->and(prSignatures(prOrders('😀😀😀')->get()))->toBe([])
            ->and(prSignatures(prOrders('   ')->get()))->toBe([])
            ->and($recorder->log())->toBe([]);
    });
});

describe('ranking', function (): void {
    it('ranks exact over prefix over suffix, then weight and recency', function (): void {
        ConfigurableOrderSearch::$sortAt = 'purchased_at';
        prUse([
            SearchField::key('signature')->weight(1),
            SearchField::key('external_number')->weight(5),
        ]);
        Order::create(['signature' => 'q-suffix', 'external_number' => 'zz-123456', 'purchased_at' => now()->subDays(5)]);
        Order::create(['signature' => 'q-prefix', 'external_number' => '123456-aa', 'purchased_at' => now()->subDays(4)]);
        Order::create(['signature' => 'q-exact', 'external_number' => '123456', 'purchased_at' => now()->subDays(3)]);
        Order::create(['signature' => '123456-weighted', 'external_number' => 'x', 'purchased_at' => now()->subDays(2)]);
        Order::create(['signature' => 'q-prefix-newer', 'external_number' => '123456-bb', 'purchased_at' => now()->subDay()]);
        prIndex();

        $results = ConfigurableOrderSearch::query('123456')->within(Order::query())->withoutStages()->get();

        expect(prSignatures($results))->toBe(['q-exact', 'q-prefix-newer', 'q-prefix', '123456-weighted', 'q-suffix']);
    });

    it('lets the field with the higher weight name the match of a record', function (): void {
        prUse([
            SearchField::key('signature')->weight(1),
            SearchField::key('external_number')->weight(5),
        ]);
        Order::create(['signature' => 'ABC-1', 'external_number' => 'ABC-1']);
        prIndex();

        $match = iterator_to_array(ConfigurableOrderSearch::query('ABC-1')->within(Order::query())->get())[0]->match;

        expect($match->kind)->toBe('external_number')
            ->and($match->alternatives)->toHaveCount(1)
            ->and($match->alternatives[0]->kind)->toBe('signature');
    });

    it('returns a record once with its best match and the other routes as alternatives', function (): void {
        Order::create(['signature' => 'ABC-123', 'external_number' => 'ABC-123-X']);
        prIndex();

        $results = iterator_to_array(prOrders('ABC-123')->get());

        expect($results)->toHaveCount(1)
            ->and($results[0]->match->mode)->toBe(Mode::Exact)
            ->and($results[0]->match->kind)->toBe('signature')
            ->and($results[0]->match->alternatives)->toHaveCount(1)
            ->and($results[0]->match->alternatives[0]->kind)->toBe('external_number')
            ->and($results[0]->match->alternatives[0]->mode)->toBe(Mode::Prefix)
            ->and($results[0]->match->alternatives[0]->value)->toBe('ABC-123-X');
    });

    it('labels the match with the label of the field', function (): void {
        Order::create(['signature' => 'ABC-123', 'external_number' => 'N-1']);
        prIndex();

        expect(iterator_to_array(prOrders('ABC-123')->get())[0]->match->label)->toBe('Signature')
            ->and(iterator_to_array(prOrders('N-1')->get())[0]->match->label)->toBe('External Number');
    });
});

describe('scope', function (): void {
    it('limits to five by default and applies the limit after within()', function (): void {
        foreach (range(1, 8) as $i) {
            Order::create([
                'signature' => 'ABC-'.$i,
                'state' => $i <= 7 ? 'shipped' : 'unshipped',
                'purchased_at' => $i === 8 ? now()->subYear() : now()->subDays(8 - $i),
            ]);
        }
        prIndex();

        $all = OrderSearch::query('ABC')->within(Order::query())->withoutStages()->get();
        $scoped = OrderSearch::query('ABC')->within(Order::query()->where('state', 'unshipped'))->withoutStages()->limit(1)->get();

        expect(iterator_to_array($all))->toHaveCount(5)
            ->and(prSignatures($all))->not->toContain('ABC-8')
            ->and(prSignatures($scoped))->toBe(['ABC-8']);
    });

    it('lets in-scope hits survive a pool full of foreign candidates', function (): void {
        foreach (range(1, 30) as $i) {
            Order::create(['signature' => sprintf('ABC-%02d', $i), 'state' => 'shipped']);
        }
        Order::create(['signature' => 'ABC-IN-SCOPE', 'state' => 'unshipped']);
        Order::create(['signature' => 'ABC', 'state' => 'shipped']);
        prIndex();

        $results = OrderSearch::query('ABC')->within(Order::query()->where('state', 'unshipped'))->withoutStages()->limit(1)->get();

        expect(prSignatures($results))->toBe(['ABC-IN-SCOPE']);
    });

    it('is fail-closed without a scope', function (): void {
        expect(fn () => OrderSearch::query('abc')->get())->toThrow(SearchScopeMissing::class);
    });

    it('hydrates the models through the host query, with its eager loads, in the order of the round', function (): void {
        Order::create(['signature' => 'ABC-2']);
        Order::create(['signature' => 'ABC']);
        prIndex();

        $results = OrderSearch::query('ABC')->within(Order::query()->with('parcels'))->get();

        expect(prSignatures($results))->toBe(['ABC', 'ABC-2'])
            ->and(iterator_to_array($results)[0]->model->relationLoaded('parcels'))->toBeTrue();
    });

    it('searches without a scope on request and excludes soft deleted records', function (): void {
        Product::create(['sku' => 'GONE-1'])->delete();
        Product::create(['sku' => 'LIVE-1']);
        prIndex();

        expect(iterator_to_array(ProductSearch::query('LIVE')->withoutScope('system job')->get()))->toHaveCount(1)
            ->and(iterator_to_array(ProductSearch::query('GONE')->withoutScope('system job')->get()))->toHaveCount(0);
    });

    it('hides the value when revealUsing denies, keeps the label and drops denied alternatives', function (): void {
        prUse([
            SearchField::key('customer_email')->label(static fn (): string => 'E-mail')->revealUsing(static fn (): bool => false),
            SearchField::key('external_number')->weight(5),
        ]);
        Order::create(['signature' => 'S-1', 'customer_email' => 'a@example.com']);
        Order::create(['signature' => 'S-2', 'external_number' => 'a@example.com.x']);
        Order::create(['signature' => 'S-3', 'customer_email' => 'b@example.com', 'external_number' => 'b@example.com']);
        prIndex();

        $results = iterator_to_array(ConfigurableOrderSearch::query('a@example.com')->within(Order::query())->get());
        $both = iterator_to_array(ConfigurableOrderSearch::query('b@example.com')->within(Order::query())->get());

        expect($results)->toHaveCount(2)
            ->and($results[0]->match->kind)->toBe('customer_email')
            ->and($results[0]->match->label)->toBe('E-mail')
            ->and($results[0]->match->value)->toBeNull()
            ->and($results[1]->match->value)->toBe('a@example.com.x')
            ->and($results[1]->match->mode)->toBe(Mode::Prefix)
            ->and($both[0]->match->kind)->toBe('external_number')
            ->and($both[0]->match->alternatives)->toBe([]);
    });

    it('reveals a name word only when every prefix name field allows it', function (): void {
        prUse([
            SearchField::name('customer_name')->revealUsing(static fn (): bool => true),
            SearchField::name('shippingAddress.name')->kind('shipping_name')->revealUsing(static fn (): bool => false),
        ]);
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        prIndex();

        $match = iterator_to_array(ConfigurableOrderSearch::query('kowal')->within(Order::query())->get())[0]->match;

        expect($match->kind)->toBe('customer_name')
            ->and($match->value)->toBeNull();
    });

    it('lets forUser() decide the reveal', function (): void {
        prUse([SearchField::key('customer_email')->revealUsing(static fn ($order, $viewer): bool => $viewer?->getAuthIdentifier() === 7)]);
        Order::create(['signature' => 'S-1', 'customer_email' => 'a@example.com']);
        prIndex();
        $viewer = new User;
        $viewer->forceFill(['id' => 7]);
        $value = static fn (SearchQuery $query): ?string => iterator_to_array($query->get())[0]->match->value;
        $base = static fn (): SearchQuery => ConfigurableOrderSearch::query('a@example.com')->within(Order::query());

        expect($value($base()))->toBeNull()
            ->and($value($base()->forUser($viewer)))->toBe('a@example.com');
    });
});

describe('stages', function (): void {
    it('searches stage zero first and reports the stage', function (): void {
        Order::create(['signature' => 'ABC-open', 'state' => 'unshipped']);
        Order::create(['signature' => 'ABC-shipped', 'state' => 'shipped']);
        prIndex();

        $results = prOrders('ABC')->get();

        expect(prSignatures($results))->toBe(['ABC-open'])
            ->and($results->stage)->toBe('open')
            ->and(iterator_to_array($results)[0]->match->stage)->toBe('open');
    });

    it('falls through to the next stage when stage zero is empty after the scope', function (): void {
        Order::create(['signature' => 'ABC-shipped', 'state' => 'shipped']);
        Order::create(['signature' => 'ABC-hidden', 'state' => 'unshipped']);
        prIndex();

        $results = OrderSearch::query('ABC')->within(Order::query()->where('state', 'shipped'))->get();

        expect(prSignatures($results))->toBe(['ABC-shipped'])
            ->and($results->stage)->toBe('all')
            ->and(iterator_to_array($results)[0]->match->stage)->toBe('all');
    });

    it('lets exact always cross stages', function (): void {
        Order::create(['signature' => 'ABC', 'state' => 'shipped']);
        Order::create(['signature' => 'ABC-2', 'state' => 'unshipped']);
        Order::create(['signature' => 'ABC-3', 'state' => 'shipped']);
        prIndex();

        $results = prOrders('ABC')->get();

        expect(prSignatures($results))->toBe(['ABC', 'ABC-2'])
            ->and($results->stage)->toBe('open');
    });

    it('does not let an exact hit block the prefix stage fallback', function (): void {
        Order::create(['signature' => 'ABC', 'state' => 'shipped']);
        Order::create(['signature' => 'ABC-2', 'state' => 'shipped']);
        prIndex();

        $results = prOrders('ABC')->get();

        expect(prSignatures($results))->toBe(['ABC', 'ABC-2'])
            ->and($results->stage)->toBe('all');
    });

    it('searches all stages at once without stages', function (): void {
        Order::create(['signature' => 'ABC-open', 'state' => 'unshipped']);
        Order::create(['signature' => 'ABC-shipped', 'state' => 'shipped']);
        prIndex();

        $results = prOrders('ABC')->withoutStages()->get();

        expect(prSignatures($results))->toHaveCount(2)
            ->and($results->stage)->toBeNull()
            ->and(iterator_to_array($results)[0]->match->stage)->toBeNull();
    });

    it('reports no stage for a definition without stages', function (): void {
        Customer::create(['name' => 'Kowalski', 'email' => 'k@example.com']);
        prIndex();

        $results = CustomerSearch::query('kowal')->within(Customer::query())->get();

        expect($results->stage)->toBeNull()
            ->and(iterator_to_array($results)[0]->match->stage)->toBeNull();
    });

    it('reads stage zero from the key rows, never from the entries', function (): void {
        Order::create(['signature' => 'ABC-1', 'state' => 'unshipped']);
        prIndex();
        $definition = app(ModelSearchRegistry::class)->for(Order::class);
        $query = prOrders('ABC');
        $statement = RoundOne::sql(['order' => ['definition' => $definition, 'query' => $query, 'host' => $query->scope()]]);

        expect($statement['sql'])->toContain('k.stage < 32767')
            ->and(substr_count($statement['sql'], TableNames::forConnection()->entries()))->toBe(1);
    });
});

describe('the one statement', function (): void {
    it('runs one statement for the whole palette', function (): void {
        $order = Order::create(['signature' => '302-4562151', 'external_number' => '302-4562151']);
        Parcel::create(['order_id' => $order->id, 'reference' => '302-4562151']);
        Product::create(['sku' => '302-4562151']);
        Invoice::create(['number' => '302-4562151', 'order_id' => $order->id]);
        Customer::create(['name' => 'Acme', 'email' => '302-4562151']);
        prIndex();
        $recorder = prRecorder();

        $palette = SearchQuery::palette(
            OrderSearch::query('302-4562151')->within(Order::query()),
            ParcelSearch::query('302-4562151')->within(Parcel::query()),
            ProductSearch::query('302-4562151')->within(Product::query()),
            InvoiceSearch::query('302-4562151')->within(Invoice::query()),
            CustomerSearch::query('302-4562151')->within(Customer::query()),
        );

        expect(prSearchStatements($recorder))->toHaveCount(1)
            ->and(array_keys($palette))->toBe(['order', 'parcel', 'product', 'invoice', 'customer'])
            ->and(count($palette['order']))->toBe(1)
            ->and(count($palette['parcel']))->toBe(1)
            ->and(count($palette['product']))->toBe(1)
            ->and(count($palette['invoice']))->toBe(1)
            ->and(count($palette['customer']))->toBe(1);
    });

    it('runs one statement for a single type too', function (): void {
        Order::create(['signature' => 'ABC-1']);
        prIndex();
        $recorder = prRecorder();

        prOrders('ABC-1')->get();

        expect(prIndexStatements($recorder))->toHaveCount(1);
    });

    it('charges the rate limit once per palette', function (): void {
        Order::create(['signature' => 'ABC-1']);
        Product::create(['sku' => 'ABC-1']);
        prIndex();
        config(['model-search.rate_limit' => ['max_attempts' => 1, 'decay_seconds' => 60]]);
        $viewer = new User;
        $viewer->forceFill(['id' => 11]);

        $palette = SearchQuery::palette(
            OrderSearch::query('ABC-1')->within(Order::query())->forUser($viewer),
            ProductSearch::query('ABC-1')->within(Product::query())->forUser($viewer),
        );

        expect(count($palette['order']))->toBe(1)
            ->and(count($palette['product']))->toBe(1);
    });

    it('refuses a palette whose queries have different viewers', function (): void {
        $first = new User;
        $first->forceFill(['id' => 1]);
        $second = new User;
        $second->forceFill(['id' => 2]);

        expect(fn () => SearchQuery::palette(prOrders('a')->forUser($first), ProductSearch::query('a')->within(Product::query())->forUser($second)))->toThrow(InvalidArgumentException::class)
            ->and(fn () => SearchQuery::palette(prOrders('a')->forUser($first), ProductSearch::query('a')->within(Product::query())->forUser(null)))->toThrow(InvalidArgumentException::class);
    });

    it('runs one statement per connection and merges the answers', function (): void {
        config(['database.connections.second' => config('database.connections.pgsql')]);
        $pdo = DB::connection()->getPdo();
        DB::connection('second')->setPdo($pdo)->setReadPdo($pdo);
        $registry = new ModelSearchRegistry;
        $registry->register(OrderSearch::class);
        $registry->register(SecondaryCustomerSearch::class);
        app()->instance(ModelSearchRegistry::class, $registry);
        DB::table(TableNames::forConnection()->state())->delete();
        Order::create(['signature' => 'ABC-1']);
        SecondaryCustomer::create(['name' => 'Acme', 'email' => 'ABC-1']);
        prIndex();
        $recorder = prRecorder();

        $palette = SearchQuery::palette(
            OrderSearch::query('ABC-1')->within(Order::query()),
            SecondaryCustomerSearch::query('ABC-1')->within(SecondaryCustomer::query()),
        );

        expect(array_keys($palette))->toBe(['order', SecondaryCustomer::class])
            ->and(count($palette['order']))->toBe(1)
            ->and(count($palette[SecondaryCustomer::class]))->toBe(1)
            ->and(prIndexStatements($recorder))->toHaveCount(2);
    });

    it('keeps one query per model in a palette', function (): void {
        expect(fn () => SearchQuery::palette(prOrders('a'), prOrders('b')))->toThrow(InvalidArgumentException::class);
    });

    it('returns an empty palette for no queries', function (): void {
        expect(SearchQuery::palette())->toBe([]);
    });
});

describe('exactOnSource', function (): void {
    it('finds a row before the drain', function (): void {
        prIndex();
        Order::create(['signature' => 'FRESH-1', 'state' => 'shipped']);

        $results = prOrders('FRESH-1')->get();

        expect(prSignatures($results))->toBe(['FRESH-1'])
            ->and(iterator_to_array($results)[0]->match->mode)->toBe(Mode::Exact)
            ->and(iterator_to_array($results)[0]->match->kind)->toBe('signature')
            ->and(iterator_to_array($results)[0]->match->value)->toBe('FRESH-1');
    });

    it('matches the value as typed, in upper case and in lower case', function (): void {
        prIndex();
        Order::create(['signature' => 'FRESH-UPPER']);
        Order::create(['signature' => 'fresh-lower']);
        Order::create(['signature' => 'Fresh-Mixed']);

        expect(prSignatures(prOrders('fresh-upper ')->get()))->toBe(['FRESH-UPPER'])
            ->and(prSignatures(prOrders('FRESH-LOWER')->get()))->toBe(['fresh-lower'])
            ->and(prSignatures(prOrders('Fresh-Mixed')->get()))->toBe(['Fresh-Mixed'])
            ->and(prSignatures(prOrders('fresh-mixed')->get()))->toBe([]);
    });

    it('dedupes with the index hit and keeps the index as the match', function (): void {
        Order::create(['signature' => 'ABC-1']);
        prIndex();

        $results = iterator_to_array(prOrders('ABC-1')->get());

        expect($results)->toHaveCount(1)
            ->and($results[0]->match->kind)->toBe('signature')
            ->and($results[0]->match->alternatives)->toBe([]);
    });

    it('stays filtered under a top-level orWhere of the host', function (): void {
        prIndex();
        Order::create(['signature' => 'FRESH-IN', 'state' => 'partially_shipped']);
        Order::create(['signature' => 'FRESH-OUT', 'state' => 'cancelled']);
        $host = Order::query()->where('state', 'unshipped')->orWhere('state', 'partially_shipped');

        $in = OrderSearch::query('FRESH-IN')->within($host)->get();
        $out = OrderSearch::query('FRESH-OUT')->within($host)->get();

        expect(prSignatures($in))->toBe(['FRESH-IN'])
            ->and(prSignatures($out))->toBe([]);
    });

    it('reports the stage of a row the index has not seen', function (): void {
        prIndex();
        Order::create(['signature' => 'FRESH-OPEN', 'state' => 'unshipped']);

        $results = prOrders('FRESH-OPEN')->get();

        expect(iterator_to_array($results)[0]->match->stage)->toBe('open');
    });
});

describe('via', function (): void {
    function prInvoices(string $phrase, ?Builder $parents = null): SearchQuery
    {
        return InvoiceSearch::query($phrase)->within(Invoice::query(), $parents === null ? [] : [Order::class => $parents]);
    }

    it('finds a record through a parent that is in the parent scope', function (): void {
        $order = Order::create(['signature' => 'ORD-1']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $order->id]);
        Invoice::create(['number' => 'FV-2']);
        prIndex();

        $results = prInvoices('ORD-1', Order::query())->get();
        $match = iterator_to_array($results)[0]->match;

        expect(array_map(static fn (SearchResult $r): string => $r->model->number, iterator_to_array($results)))->toBe(['FV-1'])
            ->and($match->kind)->toBe('order')
            ->and($match->via)->toBe('order')
            ->and($match->mode)->toBe(Mode::Exact)
            ->and($match->value)->toBe('ORD-1');
    });

    it('stays closed without a scope for the parent', function (): void {
        $order = Order::create(['signature' => 'ORD-1']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $order->id]);
        prIndex();

        expect(iterator_to_array(prInvoices('ORD-1')->get()))->toBe([]);
    });

    it('never returns a child of an out-of-scope parent', function (): void {
        foreach (range(1, 60) as $i) {
            $order = Order::create(['signature' => sprintf('ORD-%02d', $i), 'state' => 'shipped']);
            Invoice::create(['number' => 'FV-OUT-'.$i, 'order_id' => $order->id]);
        }
        $inScope = Order::create(['signature' => 'ORD-ZZ', 'state' => 'unshipped']);
        Invoice::create(['number' => 'FV-IN', 'order_id' => $inScope->id]);
        prIndex();

        $results = prInvoices('ORD', Order::query()->where('state', 'unshipped'))->limit(20)->get();

        expect(array_map(static fn (SearchResult $r): string => $r->model->number, iterator_to_array($results)))->toBe(['FV-IN']);
    });

    it('takes at most 50 parents per via path', function (): void {
        foreach (range(1, 60) as $i) {
            $order = Order::create(['signature' => sprintf('ORD-%02d', $i)]);
            Invoice::create(['number' => 'FV-'.$i, 'order_id' => $order->id]);
        }
        prIndex();

        expect(iterator_to_array(prInvoices('ORD', Order::query())->limit(100)->get()))->toHaveCount(50);
    });

    it('limits the parents per via path, not per branch', function (): void {
        foreach (range(1, 40) as $i) {
            $order = Order::create(['signature' => sprintf('ABCD-%02d', $i)]);
            Invoice::create(['number' => 'FV-A-'.$i, 'order_id' => $order->id]);
            $order = Order::create(['signature' => sprintf('%02d-ABCD', $i)]);
            Invoice::create(['number' => 'FV-B-'.$i, 'order_id' => $order->id]);
        }
        prIndex();

        expect(iterator_to_array(prInvoices('abcd', Order::query())->limit(100)->get()))->toHaveCount(50);
    });

    it('keeps the via pool flag when the kept parents have no children', function (): void {
        $invoiceFor = static function (array $numbers): void {
            foreach ($numbers as $n) {
                Invoice::create(['number' => 'FV-'.$n, 'order_id' => sprintf('00000000-0000-0000-0000-%012d', $n)]);
            }
        };
        foreach (range(1, 60) as $n) {
            Order::create(['id' => sprintf('00000000-0000-0000-0000-%012d', $n), 'signature' => sprintf('ORD-%02d', $n)]);
        }
        $round = static function (): TypeRound {
            $query = prInvoices('ORD', Order::query())->limit(100);
            $statement = RoundOne::sql(['invoice' => ['definition' => $query->definition(), 'query' => $query, 'host' => $query->scope()]]);

            return RoundOne::read(DB::select($statement['sql'], $statement['bindings']), ['invoice'], ['invoice' => 100])['invoice'];
        };

        $invoiceFor(range(55, 60));
        prIndex();
        $none = $round();
        $invoiceFor(range(1, 4));
        prIndex();
        $some = $round();

        expect($none->rows)->toBe([])
            ->and($none->viaPoolFull)->toBeTrue()
            ->and(count($some->rows))->toBe(4)
            ->and($some->viaPoolFull)->toBeTrue();
    });

    it('narrows the parents by the partition of the parent, not of the child', function (): void {
        $registry = new ModelSearchRegistry;
        $registry->register(PartitionedInvoiceSearch::class);
        $registry->register(OrderSearch::class);
        app()->instance(ModelSearchRegistry::class, $registry);
        DB::table(TableNames::forConnection()->state())->delete();
        $order = Order::create(['signature' => 'ORD-P']);
        Invoice::create(['number' => 'FV-P', 'order_id' => $order->id, 'tenant_id' => 5]);
        Invoice::create(['number' => 'FV-Q', 'order_id' => $order->id, 'tenant_id' => 6]);
        prIndex();

        $results = PartitionedInvoiceSearch::query('ORD-P')->within(Invoice::query(), [Order::class => Order::query()])->partition(5)->get();

        expect(array_map(static fn (SearchResult $r): string => $r->model->number, iterator_to_array($results)))->toBe(['FV-P']);
    });

    it('reads the stage of a child found through a parent from the child', function (): void {
        $registry = new ModelSearchRegistry;
        $registry->register(StagedInvoiceSearch::class);
        $registry->register(OrderSearch::class);
        app()->instance(ModelSearchRegistry::class, $registry);
        DB::table(TableNames::forConnection()->state())->delete();
        $order = Order::create(['signature' => 'ORD-S']);
        Invoice::create(['number' => 'FV-S', 'order_id' => $order->id]);
        prIndex();

        $results = StagedInvoiceSearch::query('ORD-S')->within(Invoice::query(), [Order::class => Order::query()])->get();

        expect(prSignaturesOf($results, 'number'))->toBe(['FV-S'])
            ->and(iterator_to_array($results)[0]->match->stage)->toBe('attached');
    });

    it('dedupes to the best mode with alternatives', function (): void {
        $order = Order::create(['signature' => 'ORD-1']);
        Invoice::create(['number' => 'ORD-1', 'order_id' => $order->id]);
        prIndex();

        $results = iterator_to_array(prInvoices('ORD-1', Order::query())->get());

        expect($results)->toHaveCount(1)
            ->and($results[0]->match->via)->toBeNull()
            ->and($results[0]->match->kind)->toBe('number')
            ->and($results[0]->match->alternatives)->toHaveCount(1)
            ->and($results[0]->match->alternatives[0]->via)->toBe('order')
            ->and($results[0]->match->alternatives[0]->kind)->toBe('order');
    });

    it('ranks a hit through a parent below a direct hit of the same mode', function (): void {
        $order = Order::create(['signature' => 'ORD-1']);
        Invoice::create(['number' => 'FV-VIA', 'order_id' => $order->id]);
        Invoice::create(['number' => 'ORD-1']);
        prIndex();

        $results = iterator_to_array(prInvoices('ORD-1', Order::query())->get());

        expect(array_map(static fn (SearchResult $r): string => $r->model->number, $results))->toBe(['ORD-1', 'FV-VIA']);
    });

    it('honours the owner key of the relation', function (): void {
        $registry = new ModelSearchRegistry;
        $registry->register(OwnerKeyInvoiceSearch::class);
        $registry->register(OrderSearch::class);
        app()->instance(ModelSearchRegistry::class, $registry);
        DB::table(TableNames::forConnection()->state())->delete();
        $inScope = Order::create(['signature' => 'ORD-B', 'state' => 'unshipped']);
        Order::create(['signature' => 'ORD-A', 'external_number' => $inScope->id, 'state' => 'shipped']);
        Invoice::create(['number' => 'FV-1', 'external_reference' => $inScope->id]);
        prIndex();
        $scope = Order::query()->where('state', 'unshipped');

        $wrongParent = OwnerKeyInvoiceSearch::query('ORD-B')->within(Invoice::query(), [Order::class => $scope])->get();
        $realParent = OwnerKeyInvoiceSearch::query('ORD-A')->within(Invoice::query(), [Order::class => Order::query()])->get();

        expect(iterator_to_array($wrongParent))->toBe([])
            ->and(iterator_to_array($realParent))->toHaveCount(1);
    });

    it('fails loudly when the parent has no definition', function (): void {
        $registry = new ModelSearchRegistry;
        $registry->register(InvoiceSearch::class);
        app()->instance(ModelSearchRegistry::class, $registry);
        DB::table(TableNames::forConnection()->state())->delete();
        prIndex();

        expect(fn () => InvoiceSearch::query('ORD-1')->within(Invoice::query(), [Order::class => Order::query()])->get())->toThrow(InvalidSearchDefinition::class);
    });

    it('fills the parent scope from the other queries of the palette', function (): void {
        $order = Order::create(['signature' => 'ORD-1', 'state' => 'unshipped']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $order->id]);
        $hidden = Order::create(['signature' => 'ORD-1', 'state' => 'shipped']);
        Invoice::create(['number' => 'FV-HIDDEN', 'order_id' => $hidden->id]);
        prIndex();

        $palette = SearchQuery::palette(
            OrderSearch::query('ORD-1')->within(Order::query()->where('state', 'unshipped')),
            InvoiceSearch::query('ORD-1')->within(Invoice::query()),
        );

        expect(array_map(static fn (SearchResult $r): string => $r->model->number, iterator_to_array($palette['invoice'])))->toBe(['FV-1']);
    });

    it('lets an explicit parent scope win over the palette', function (): void {
        $order = Order::create(['signature' => 'ORD-1', 'state' => 'shipped']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $order->id]);
        prIndex();

        $palette = SearchQuery::palette(
            OrderSearch::query('ORD-1')->within(Order::query()->where('state', 'unshipped')),
            InvoiceSearch::query('ORD-1')->within(Invoice::query(), [Order::class => Order::query()]),
        );

        expect(count($palette['invoice']))->toBe(1)
            ->and(count($palette['order']))->toBe(0);
    });
});

describe('partition', function (): void {
    beforeEach(function (): void {
        config(['model-search.partition_column' => 'tenant_id']);
    });

    it('searches inside the partition only, in the index and in the source', function (): void {
        Order::create(['signature' => 'ABC-1', 'tenant_id' => 1]);
        Order::create(['signature' => 'ABC-2', 'tenant_id' => 2]);
        prIndex();
        Order::create(['signature' => 'FRESH-A', 'tenant_id' => 1]);
        Order::create(['signature' => 'FRESH-B', 'tenant_id' => 2]);

        expect(prSignatures(prOrders('ABC')->partition(2)->get()))->toBe(['ABC-2'])
            ->and(prSignatures(prOrders('ABC')->partition('1')->get()))->toBe(['ABC-1'])
            ->and(prSignatures(prOrders('FRESH-A')->partition(2)->get()))->toBe([])
            ->and(prSignatures(prOrders('FRESH-A')->partition(1)->get()))->toBe(['FRESH-A']);
    });

    it('requires a partition value', function (): void {
        Order::create(['signature' => 'ABC-1', 'tenant_id' => 1]);
        prIndex();

        expect(fn () => prOrders('ABC-1')->get())->toThrow(PartitionMissing::class);
    });
});

describe('names and only()', function (): void {
    it('refuses only() that names some of the prefix name fields, and accepts all or none', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        prIndex();

        expect(fn () => prOrders('kowal')->only(['customer_name'])->get())->toThrow(UnsupportedQuery::class)
            ->and(prSignatures(prOrders('kowal')->only(['customer_name', 'shipping_name'])->get()))->toBe(['S-1'])
            ->and(prSignatures(prOrders('kowal')->only(['signature'])->get()))->toBe([])
            ->and(prSignatures(prOrders('kowal')->get()))->toBe(['S-1']);
    });

    it('reports a name word as the first prefix name field with its label', function (): void {
        prUse([
            SearchField::name('customer_name')->label(static fn (): string => 'Customer'),
            SearchField::name('shippingAddress.name')->kind('shipping_name'),
        ]);
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        prIndex();

        $match = iterator_to_array(ConfigurableOrderSearch::query('kowal')->within(Order::query())->get())[0]->match;

        expect($match->kind)->toBe('customer_name')
            ->and($match->label)->toBe('Customer');
    });
});

describe('plan', function (): void {
    it('keeps the early stop of a multi-word name phrase: index scan under limit, nested loop, no semi join', function (): void {
        DB::statement("insert into orders (id, signature, customer_name, state, created_at, updated_at) select gen_random_uuid(), 'ABC-' || g, 'Jan Kowalski' || g, 'shipped', now(), now() from generate_series(1, 20000) g");
        DB::statement("insert into model_search_keys (subject_type, subject_id, kind, key, value, stage) select 'order', id::text, 'name_word', 'jan', customer_name, 32767 from orders");
        DB::statement("insert into model_search_keys (subject_type, subject_id, kind, key, value, stage) select 'order', id::text, 'name_word', lower(substr(customer_name, 5)), customer_name, 32767 from orders");
        DB::statement('analyze model_search_keys');
        $query = OrderSearch::query('jan kowal')->within(Order::query())->withoutStages();
        $statement = RoundOne::sql(['order' => ['definition' => app(ModelSearchRegistry::class)->for(Order::class), 'query' => $query, 'host' => $query->scope()]]);
        DB::statement('SET LOCAL max_parallel_workers_per_gather = 0');
        $plan = implode("\n", array_map(static fn (object $row): string => (string) current((array) $row), DB::select('EXPLAIN '.$statement['sql'], $statement['bindings'])));

        expect($plan)->toContain('Index Scan using model_search_keys_name_word')
            ->and($plan)->toContain('Nested Loop')
            ->and($plan)->not->toContain('Semi Join');
    });
});

describe('privacy and failure', function (): void {
    it('never lets the phrase run on the host connection', function (): void {
        Order::create(['signature' => 'SECRETPHRASE', 'customer_name' => 'Secretphrase Person']);
        prIndex();
        $heard = [];
        DB::listen(static function ($query) use (&$heard): void {
            $heard[] = $query->sql.json_encode($query->bindings);
        });
        DB::enableQueryLog();

        prOrders('SECRETPHRASE')->get();
        prOrders('secretphrase')->get();
        $logged = json_encode(DB::getQueryLog());
        DB::disableQueryLog();

        expect(stripos($logged.implode('', $heard), 'secretphrase'))->toBeFalse()
            ->and($heard)->not->toBe([]);
    });

    it('keeps the phrase to bound parameters and the stage and kind predicates literal', function (): void {
        Order::create(['signature' => 'ABCDEF']);
        prIndex();
        $recorder = prRecorder();

        prOrders('ABCDEF')->get();
        $statement = prIndexStatements($recorder)[0];

        expect(stripos($statement['query'], 'abcdef'))->toBeFalse()
            ->and(json_encode($statement['bindings']))->toContain('abcdef')
            ->and($statement['query'])->toContain("kind <> 'name_word'");
    });

    it('surfaces a timeout as timedOut, never as an empty result', function (): void {
        Order::create(['signature' => 'ABC-1']);
        prIndex();
        config(['model-search.search.timeout_ms' => 50]);
        $slow = Order::query()->whereRaw('pg_sleep(0.4) is not null');

        $results = OrderSearch::query('ABC-1')->within($slow)->get();

        expect($results->timedOut)->toBeTrue()
            ->and(iterator_to_array($results))->toBe([]);
    });

    it('keeps the phrase out of the message of a database failure', function (): void {
        Order::create(['signature' => 'secretphrase']);
        prIndex();
        $broken = Order::query()->whereRaw('no_such_column = 1');

        try {
            OrderSearch::query('secretphrase')->within($broken)->get();
            $this->fail('Expected a failure.');
        } catch (SearchFailed|SearchTimedOut $exception) {
            expect($exception->getMessage())->not->toContain('secretphrase')
                ->and($exception->getPrevious())->toBeNull();
        }
    });
});

describe('readiness', function (): void {
    function prUnready(string $type = 'order'): void
    {
        DB::table(TableNames::forConnection()->state())->where('subject_type', $type)->update(['ready_at' => null]);
        (new IndexState)->forget($type);
    }

    it('falls back to the database driver until the index is ready, and says the index is building', function (): void {
        Order::create(['signature' => 'ABC-1', 'customer_name' => 'Kowalski']);
        $recorder = prRecorder();
        prUnready();

        $results = prOrders('ABC-1')->get();
        $names = prOrders('kowal')->get();

        expect(prSignatures($results))->toBe(['ABC-1'])
            ->and(prSignatures($names))->toBe(['ABC-1'])
            ->and($results->indexBuilding)->toBeTrue()
            ->and(prIndexStatements($recorder))->toBe([]);
    });

    it('does not say the index is building when it is ready', function (): void {
        Order::create(['signature' => 'ABC-1']);
        prIndex();

        expect(prOrders('ABC-1')->get()->indexBuilding)->toBeFalse();
    });

    it('searches only the source columns of a type without a fallback, and never throws', function (): void {
        ConfigurableOrderSearch::$fallback = false;
        prUse([
            SearchField::key('signature')->exactOnSource(),
            SearchField::key('external_number'),
            SearchField::name('customer_name'),
        ]);
        Order::create(['signature' => 'ABC-1', 'external_number' => 'ABC-1', 'customer_name' => 'Abc Person']);
        Order::create(['signature' => 'S-2', 'external_number' => 'EXT-9', 'customer_name' => 'Kowalski']);
        $recorder = prRecorder();
        $find = static fn (string $phrase): SearchResults => ConfigurableOrderSearch::query($phrase)->within(Order::query())->get();

        $exact = $find('ABC-1');
        $prefix = $find('ABC');
        $name = $find('kowal');
        $other = $find('EXT-9');

        expect(prSignatures($exact))->toBe(['ABC-1'])
            ->and(iterator_to_array($exact)[0]->match->kind)->toBe('signature')
            ->and($exact->indexBuilding)->toBeTrue()
            ->and(prSignatures($prefix))->toBe([])
            ->and(prSignatures($name))->toBe([])
            ->and(prSignatures($other))->toBe([])
            ->and($other->indexBuilding)->toBeTrue()
            ->and(prIndexStatements($recorder))->toBe([]);
    });

    it('runs nothing for a type without a fallback and without a source column', function (): void {
        ConfigurableOrderSearch::$fallback = false;
        prUse([SearchField::key('signature'), SearchField::name('customer_name')]);
        Order::create(['signature' => 'ABC-1']);
        $recorder = prRecorder();

        $results = ConfigurableOrderSearch::query('ABC-1')->within(Order::query())->get();

        expect(prSignatures($results))->toBe([])
            ->and($results->indexBuilding)->toBeTrue()
            ->and($recorder->log())->toBe([]);
    });

    it('serves the ready types from the index and the others from the tables in one palette', function (): void {
        Order::create(['signature' => '302-4562151']);
        Product::create(['sku' => '302-4562151']);
        prIndex();
        prUnready();
        $recorder = prRecorder();

        $palette = SearchQuery::palette(
            OrderSearch::query('302-4562151')->within(Order::query()),
            ProductSearch::query('302-4562151')->within(Product::query()),
        );

        expect(count($palette['order']))->toBe(1)
            ->and(count($palette['product']))->toBe(1)
            ->and($palette['order']->indexBuilding)->toBeTrue()
            ->and($palette['product']->indexBuilding)->toBeTrue()
            ->and(prIndexStatements($recorder))->toHaveCount(1);
    });
});
