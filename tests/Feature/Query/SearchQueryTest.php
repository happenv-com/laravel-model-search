<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Exceptions\PartitionMissing;
use Happenv\ModelSearch\Exceptions\SearchFailed;
use Happenv\ModelSearch\Exceptions\SearchScopeMissing;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Happenv\ModelSearch\Exceptions\SearchTooBroad;
use Happenv\ModelSearch\Exceptions\UnsupportedHostQuery;
use Happenv\ModelSearch\Exceptions\UnsupportedQuery;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Query\SearchResult;
use Happenv\ModelSearch\Query\SearchResults;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Address;
use Happenv\ModelSearch\Tests\Fixtures\Models\Customer;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Models\Product;
use Happenv\ModelSearch\Tests\Fixtures\Models\SubOrder;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\CustomerSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\InvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OwnerKeyInvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ProductSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;

covers(SearchQuery::class);

beforeEach(function (): void {
    config(['model-search.driver' => 'database']);
});

afterEach(fn () => ConfigurableOrderSearch::reset());

/**
 * @param  list<SearchField>  $fields
 */
function useOrderFields(array $fields): void
{
    ConfigurableOrderSearch::$fields = $fields;
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);
    app()->instance(ModelSearchRegistry::class, $registry);
}

/**
 * @return list<string>
 */
function signaturesOf(SearchResults $results): array
{
    return array_map(static fn (SearchResult $result): string => $result->model->signature, iterator_to_array($results));
}

function orderSearch(string $phrase): SearchQuery
{
    return OrderSearch::query($phrase)->within(Order::query());
}

describe('fail-closed scope', function (): void {
    it('refuses every execution method without within() or withoutScope()', function (): void {
        $query = OrderSearch::query('abc');

        expect(fn () => $query->get())->toThrow(SearchScopeMissing::class)
            ->and(fn () => $query->similar())->toThrow(SearchScopeMissing::class)
            ->and(fn () => $query->apply(Order::query()))->toThrow(SearchScopeMissing::class)
            ->and(fn () => $query->candidates())->toThrow(SearchScopeMissing::class);
    });

    it('requires the ceremony for candidates, within() is not enough', function (): void {
        expect(fn () => OrderSearch::query('abc')->within(Order::query())->candidates())->toThrow(SearchScopeMissing::class);
    });

    it('requires a reason for withoutScope()', function (): void {
        expect(fn () => OrderSearch::query('abc')->withoutScope('  '))->toThrow(InvalidArgumentException::class);
    });

    it('rejects a host query of another model', function (): void {
        expect(fn () => OrderSearch::query('abc')->within(Customer::query()))->toThrow(UnsupportedHostQuery::class);
    });

    it('rejects an unsupported host query when within() is called', function (): void {
        expect(fn () => OrderSearch::query('abc')->within(Order::query()->limit(3)))->toThrow(UnsupportedHostQuery::class);
    });

    it('is immutable', function (): void {
        $base = OrderSearch::query('abc');
        $scoped = $base->within(Order::query());
        $limited = $scoped->limit(1);

        expect($scoped)->not->toBe($base)
            ->and($limited)->not->toBe($scoped)
            ->and(fn () => $base->get())->toThrow(SearchScopeMissing::class);
    });
});

describe('partition', function (): void {
    beforeEach(function (): void {
        config(['model-search.partition_column' => 'tenant_id']);
    });

    it('requires a partition value when the definition is partitioned', function (): void {
        Order::create(['signature' => 'ABC-1', 'tenant_id' => 1]);

        expect(fn () => orderSearch('ABC-1')->get())->toThrow(PartitionMissing::class)
            ->and(fn () => orderSearch('ABC-1')->apply(Order::query()))->toThrow(PartitionMissing::class)
            ->and(fn () => OrderSearch::query('ABC-1')->withoutScope('test')->candidates())->toThrow(PartitionMissing::class);
    });

    it('searches inside the partition only', function (): void {
        Order::create(['signature' => 'ABC-1', 'tenant_id' => 1]);
        Order::create(['signature' => 'ABC-2', 'tenant_id' => 2]);

        expect(signaturesOf(orderSearch('ABC')->partition(2)->get()))->toBe(['ABC-2'])
            ->and(signaturesOf(orderSearch('ABC')->partition('1')->get()))->toBe(['ABC-1']);
    });

    it('refuses a partition value when nothing is partitioned', function (): void {
        config(['model-search.partition_column' => null]);

        expect(fn () => orderSearch('ABC')->partition(1)->get())->toThrow(PartitionMissing::class);
    });
});

describe('matching', function (): void {
    it('finds by exact, prefix, suffix, infix and name prefix', function (): void {
        useOrderFields([
            SearchField::key('external_number')->matches(Mode::Exact, Mode::Prefix, Mode::Suffix, Mode::Infix),
            SearchField::name('customer_name'),
        ]);
        Order::create(['signature' => 'S-1', 'external_number' => '302-4562151-0001', 'customer_name' => 'Jan Kowalski']);
        Order::create(['signature' => 'S-2', 'external_number' => '999-1111111-2222', 'customer_name' => 'Anna Nowak-Zielinska']);
        $find = static fn (string $phrase): SearchResults => ConfigurableOrderSearch::query($phrase)->within(Order::query())->get();

        $exact = $find('302 4562151 0001');
        $prefix = $find('302-456');
        $suffix = $find('4562151-0001');
        $infix = $find('562151');
        $name = $find('kowal');
        $secondWord = $find('zieli');

        expect(signaturesOf($exact))->toBe(['S-1'])
            ->and(iterator_to_array($exact)[0]->match->mode)->toBe(Mode::Exact)
            ->and(signaturesOf($prefix))->toBe(['S-1'])
            ->and(iterator_to_array($prefix)[0]->match->mode)->toBe(Mode::Prefix)
            ->and(signaturesOf($suffix))->toBe(['S-1'])
            ->and(iterator_to_array($suffix)[0]->match->mode)->toBe(Mode::Suffix)
            ->and(signaturesOf($infix))->toBe(['S-1'])
            ->and(iterator_to_array($infix)[0]->match->mode)->toBe(Mode::Infix)
            ->and(signaturesOf($name))->toBe(['S-1'])
            ->and(iterator_to_array($name)[0]->match->mode)->toBe(Mode::Prefix)
            ->and(iterator_to_array($name)[0]->match->kind)->toBe('customer_name')
            ->and(iterator_to_array($name)[0]->match->value)->toBe('Jan Kowalski')
            ->and(signaturesOf($secondWord))->toBe(['S-2']);
    });

    it('matches every word of a name phrase, in any order, as a word prefix', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        Order::create(['signature' => 'S-2', 'customer_name' => 'Jan Nowak']);

        expect(signaturesOf(orderSearch('kowal jan')->get()))->toBe(['S-1'])
            ->and(signaturesOf(orderSearch('wal')->get()))->toBe([]);
    });

    it('finds a key through a relation path and ignores separators in the stored value', function (): void {
        $address = Address::create(['phone' => '+48 600-100-200']);
        Order::create(['signature' => 'S-1', 'billing_address_id' => $address->id]);
        Order::create(['signature' => 'S-2']);

        $results = orderSearch('48600100200')->get();

        expect(signaturesOf($results))->toBe(['S-1'])
            ->and(iterator_to_array($results)[0]->match->kind)->toBe('phone')
            ->and(iterator_to_array($results)[0]->match->mode)->toBe(Mode::Exact);
    });

    it('finds a record through a name on a relation path', function (): void {
        $address = Address::create(['name' => 'Maria Wisniewska']);
        Order::create(['signature' => 'S-1', 'shipping_address_id' => $address->id]);

        $results = orderSearch('wisniew')->get();

        expect(signaturesOf($results))->toBe(['S-1'])
            ->and(iterator_to_array($results)[0]->match->kind)->toBe('shipping_name');
    });

    it('reads every locale of a translatable name and skips deleted records', function (): void {
        Product::create(['sku' => 'P-1', 'name' => ['pl' => 'Sprzet domowy', 'en' => 'Household gear']]);
        $gone = Product::create(['sku' => 'P-2', 'name' => ['en' => 'Household tools']]);
        $gone->delete();

        $results = ProductSearch::query('house')->within(Product::query())->get();

        expect(array_map(static fn (SearchResult $r): string => $r->model->sku, iterator_to_array($results)))->toBe(['P-1']);
    });

    it('uses the locale variants of the phrase', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Mueller GmbH']);

        expect(signaturesOf(orderSearch('Müller')->get()))->toBe([])
            ->and(signaturesOf(orderSearch('Müller')->locale('de')->get()))->toBe(['S-1']);
    });

    it('matches a diacritic name written the way the source stores it', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Müller GmbH']);

        expect(signaturesOf(orderSearch('Müller')->get()))->toBe(['S-1']);
    });

    it('ignores the stored case and the separators of a key', function (): void {
        Order::create(['signature' => 'OR-9173/A']);

        expect(signaturesOf(orderSearch('or9173a')->get()))->toBe(['OR-9173/A']);
    });

    it('treats like metacharacters as literal', function (): void {
        Order::create(['signature' => 'S-1', 'external_number' => 'ab%cd1234']);
        Order::create(['signature' => 'S-2', 'external_number' => 'abXcd1234']);
        Order::create(['signature' => 'S-3', 'customer_name' => '100% Cotton']);
        Order::create(['signature' => 'S-4', 'customer_name' => '100 Cotton']);

        expect(signaturesOf(orderSearch('ab%cd')->get()))->toBe(['S-1'])
            ->and(signaturesOf(orderSearch('ab%')->get()))->toBe(['S-1'])
            ->and(signaturesOf(orderSearch('%%%')->get()))->toBe([])
            ->and(signaturesOf(orderSearch('cot%')->get()))->toBe([]);
    });

    it('respects only() and using()', function (): void {
        Order::create(['signature' => 'ABC-1', 'external_number' => 'ABC-1-EXT']);
        Order::create(['signature' => 'ZZZ-OTHER', 'external_number' => 'ABC-1']);
        Order::create(['signature' => 'Q-3', 'customer_name' => 'Abc Person']);
        $find = static function (string $phrase, Closure $change): array {
            $names = signaturesOf($change(orderSearch($phrase))->get());
            sort($names);

            return $names;
        };

        expect($find('ABC', fn (SearchQuery $q) => $q))->toBe(['ABC-1', 'Q-3', 'ZZZ-OTHER'])
            ->and($find('ABC', fn (SearchQuery $q) => $q->only(['signature'])))->toBe(['ABC-1'])
            ->and($find('ABC', fn (SearchQuery $q) => $q->only(['external_number'])))->toBe(['ABC-1', 'ZZZ-OTHER'])
            ->and($find('ABC', fn (SearchQuery $q) => $q->only(['customer_name', 'shipping_name'])))->toBe(['Q-3'])
            ->and(fn () => orderSearch('ABC')->only(['customer_name'])->get())->toThrow(UnsupportedQuery::class)
            ->and($find('ABC-1', fn (SearchQuery $q) => $q->using(Mode::Exact)))->toBe(['ABC-1', 'ZZZ-OTHER'])
            ->and($find('ABC-1', fn (SearchQuery $q) => $q->using(Mode::Exact)->only(['external_number'])))->toBe(['ZZZ-OTHER'])
            ->and($find('ABC', fn (SearchQuery $q) => $q->using(Mode::Exact)))->toBe([])
            ->and($find('person', fn (SearchQuery $q) => $q->using(Mode::Exact, Mode::Suffix)))->toBe([])
            ->and($find('person', fn (SearchQuery $q) => $q->using(Mode::Prefix)))->toBe(['Q-3']);
    });

    it('skips name fields for an identifier-like phrase', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => '123456 Warehouse']);
        Order::create(['signature' => 'S-2', 'customer_name' => 'Plain', 'customer_email' => 'a@example.com']);

        expect(signaturesOf(orderSearch('123456')->get()))->toBe([])
            ->and(signaturesOf(orderSearch('a@example.com')->get()))->toBe(['S-2']);
    });

    it('skips a mode when the phrase is below its minimum length', function (): void {
        useOrderFields([
            SearchField::key('external_number')->matches(Mode::Exact, Mode::Prefix, Mode::Suffix, Mode::Infix),
            SearchField::name('customer_name'),
        ]);
        Order::create(['signature' => 'S-1', 'external_number' => 'ab12345678', 'customer_name' => 'Kowalski']);
        Order::create(['signature' => 'S-2', 'external_number' => 'ab']);
        $find = static fn (string $phrase): array => signaturesOf(ConfigurableOrderSearch::query($phrase)->within(Order::query())->get());

        expect($find('ab'))->toBe(['S-2'])
            ->and($find('ab1'))->toBe(['S-1'])
            ->and($find('678'))->toBe([])
            ->and($find('5678'))->toBe([])
            ->and($find('345678'))->toBe(['S-1'])
            ->and($find('ko'))->toBe([])
            ->and($find('kow'))->toBe(['S-1']);
    });

    it('measures the name minimum on the longest word of the phrase', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jo Li Kowalski']);

        expect(signaturesOf(orderSearch('jo li kow')->get()))->toBe(['S-1'])
            ->and(signaturesOf(orderSearch('jo li')->get()))->toBe([]);
    });

    it('returns nothing for a phrase that normalizes to nothing, never a match-all', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Kowalski']);
        $table = orderSearch('日本語')->apply(Order::query());

        expect(signaturesOf(orderSearch('日本語')->get()))->toBe([])
            ->and(signaturesOf(orderSearch('😀😀😀')->get()))->toBe([])
            ->and(signaturesOf(orderSearch('   ')->get()))->toBe([])
            ->and($table->count())->toBe(0)
            ->and(orderSearch('日本語')->withoutScope('test')->candidates())->toHaveCount(0)
            ->and(signaturesOf(orderSearch('日本語')->similar()))->toBe([]);
    });

    it('does not serve via fields without a scope for the parent', function (): void {
        $order = Order::create(['signature' => 'ORD-1']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $order->id]);

        $results = InvoiceSearch::query('ORD-1')->within(Invoice::query())->get();

        expect(iterator_to_array($results))->toBe([]);
    });
});

describe('ranking', function (): void {
    it('ranks exact over prefix over suffix, then weight and recency', function (): void {
        ConfigurableOrderSearch::$sortAt = 'purchased_at';
        useOrderFields([
            SearchField::key('signature')->weight(1),
            SearchField::key('external_number')->weight(5),
        ]);
        Order::create(['signature' => 'q-suffix', 'external_number' => 'zz-123456', 'purchased_at' => now()->subDays(5)]);
        Order::create(['signature' => 'q-prefix', 'external_number' => '123456-aa', 'purchased_at' => now()->subDays(4)]);
        Order::create(['signature' => 'q-exact', 'external_number' => '123456', 'purchased_at' => now()->subDays(3)]);
        Order::create(['signature' => '123456-weighted', 'external_number' => 'x', 'purchased_at' => now()->subDays(2)]);
        Order::create(['signature' => 'q-prefix-newer', 'external_number' => '123456-bb', 'purchased_at' => now()->subDay()]);

        $results = ConfigurableOrderSearch::query('123456')->within(Order::query())->withoutStages()->get();

        expect(signaturesOf($results))->toBe(['q-exact', 'q-prefix-newer', 'q-prefix', '123456-weighted', 'q-suffix']);
    });

    it('breaks a tie on weight before recency', function (): void {
        ConfigurableOrderSearch::$sortAt = 'purchased_at';
        useOrderFields([
            SearchField::key('signature')->weight(1),
            SearchField::key('external_number')->weight(5),
        ]);
        Order::create(['signature' => '123456-aa', 'external_number' => 'x', 'purchased_at' => now()]);
        Order::create(['signature' => 'y', 'external_number' => '123456-bb', 'purchased_at' => now()->subYear()]);

        $results = ConfigurableOrderSearch::query('123456')->within(Order::query())->withoutStages()->get();

        expect(signaturesOf($results))->toBe(['y', '123456-aa']);
    });

    it('returns a record once with its best match', function (): void {
        Order::create(['signature' => 'ABC-123', 'external_number' => 'ABC-123-X']);

        $results = orderSearch('ABC-123')->get();

        expect(iterator_to_array($results))->toHaveCount(1)
            ->and(iterator_to_array($results)[0]->match->mode)->toBe(Mode::Exact)
            ->and(iterator_to_array($results)[0]->match->kind)->toBe('signature')
            ->and(iterator_to_array($results)[0]->match->alternatives)->toBe([]);
    });

    it('labels the match with the label of the field', function (): void {
        Order::create(['signature' => 'ABC-123', 'external_number' => 'N-1']);

        $results = orderSearch('ABC-123')->get();
        $other = orderSearch('N-1')->get();

        expect(iterator_to_array($results)[0]->match->label)->toBe('Signature')
            ->and(iterator_to_array($other)[0]->match->label)->toBe('External Number');
    });
});

describe('limit and scope', function (): void {
    it('limits to five by default and applies the limit after within()', function (): void {
        foreach (range(1, 8) as $i) {
            Order::create([
                'signature' => 'ABC-'.$i,
                'state' => $i <= 7 ? 'shipped' : 'unshipped',
                'purchased_at' => $i === 8 ? now()->subYear() : now()->subDays(8 - $i),
            ]);
        }

        $all = OrderSearch::query('ABC')->within(Order::query())->withoutStages()->get();
        $scoped = OrderSearch::query('ABC')->within(Order::query()->where('state', 'unshipped'))->withoutStages()->limit(1)->get();

        expect(iterator_to_array($all))->toHaveCount(5)
            ->and(signaturesOf($all))->not->toContain('ABC-8')
            ->and(signaturesOf($scoped))->toBe(['ABC-8']);
    });

    it('rejects a limit below one', function (): void {
        expect(fn () => orderSearch('ABC')->limit(0))->toThrow(InvalidArgumentException::class);
    });

    it('hydrates the models through the host query, with its eager loads', function (): void {
        Order::create(['signature' => 'ABC-1']);

        $results = OrderSearch::query('ABC')->within(Order::query()->with('parcels'))->get();

        expect(iterator_to_array($results)[0]->model->relationLoaded('parcels'))->toBeTrue();
    });

    it('searches without a scope on request', function (): void {
        Order::create(['signature' => 'ABC-1']);

        expect(signaturesOf(OrderSearch::query('ABC')->withoutScope('system job')->get()))->toBe(['ABC-1']);
    });

    it('still excludes soft deleted records from a scope-less search', function (): void {
        Product::create(['sku' => 'GONE-1'])->delete();
        Product::create(['sku' => 'LIVE-1']);

        $live = ProductSearch::query('LIVE')->withoutScope('system job')->get();
        $gone = ProductSearch::query('GONE')->withoutScope('system job')->get();

        expect(iterator_to_array($live))->toHaveCount(1)
            ->and(iterator_to_array($gone))->toHaveCount(0);
    });
});

describe('reveal', function (): void {
    it('hides the value when revealUsing denies and keeps the label', function (): void {
        useOrderFields([
            SearchField::key('customer_email')->label(static fn (): string => 'E-mail')->revealUsing(static fn (): bool => false),
            SearchField::key('external_number'),
        ]);
        Order::create(['signature' => 'S-1', 'customer_email' => 'a@example.com']);
        Order::create(['signature' => 'S-2', 'external_number' => 'a@example.com.x']);

        $results = iterator_to_array(ConfigurableOrderSearch::query('a@example.com')->within(Order::query())->get());

        expect($results)->toHaveCount(2)
            ->and($results[0]->match->kind)->toBe('customer_email')
            ->and($results[0]->match->label)->toBe('E-mail')
            ->and($results[0]->match->value)->toBeNull()
            ->and($results[1]->match->value)->toBe('a@example.com.x');
    });

    it('hides the source name of a name hit by the rule of its field', function (): void {
        useOrderFields([
            SearchField::name('customer_name')->revealUsing(static fn (): bool => false),
        ]);
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);

        $match = iterator_to_array(ConfigurableOrderSearch::query('kowal')->within(Order::query())->get())[0]->match;

        expect($match->kind)->toBe('customer_name')
            ->and($match->value)->toBeNull();
    });
});

describe('stages', function (): void {
    it('searches stage zero first and reports the stage', function (): void {
        Order::create(['signature' => 'ABC-open', 'state' => 'unshipped']);
        Order::create(['signature' => 'ABC-shipped', 'state' => 'shipped']);

        $results = orderSearch('ABC')->get();

        expect(signaturesOf($results))->toBe(['ABC-open'])
            ->and($results->stage)->toBe('open')
            ->and(iterator_to_array($results)[0]->match->stage)->toBe('open');
    });

    it('falls through to the next stage when stage zero is empty after the scope', function (): void {
        Order::create(['signature' => 'ABC-shipped', 'state' => 'shipped']);
        Order::create(['signature' => 'ABC-hidden', 'state' => 'unshipped', 'customer_email' => 'hidden']);

        $results = OrderSearch::query('ABC')->within(Order::query()->where('state', 'shipped'))->get();

        expect(signaturesOf($results))->toBe(['ABC-shipped'])
            ->and($results->stage)->toBe('all')
            ->and(iterator_to_array($results)[0]->match->stage)->toBe('all');
    });

    it('always searches Exact in every stage, and lets the other modes move on', function (): void {
        Order::create(['signature' => 'ABC', 'state' => 'shipped']);
        Order::create(['signature' => 'ABC-2', 'state' => 'shipped']);

        $results = orderSearch('ABC')->get();

        expect(signaturesOf($results))->toBe(['ABC', 'ABC-2'])
            ->and($results->stage)->toBe('all');
    });

    it('does not move on while stage zero has a non-exact hit', function (): void {
        Order::create(['signature' => 'ABC', 'state' => 'shipped']);
        Order::create(['signature' => 'ABC-2', 'state' => 'unshipped']);
        Order::create(['signature' => 'ABC-3', 'state' => 'shipped']);

        $results = orderSearch('ABC')->get();

        expect(signaturesOf($results))->toBe(['ABC', 'ABC-2'])
            ->and($results->stage)->toBe('open');
    });

    it('searches all stages at once without stages', function (): void {
        Order::create(['signature' => 'ABC-open', 'state' => 'unshipped']);
        Order::create(['signature' => 'ABC-shipped', 'state' => 'shipped']);

        $results = orderSearch('ABC')->withoutStages()->get();

        expect(signaturesOf($results))->toHaveCount(2)
            ->and($results->stage)->toBeNull()
            ->and(iterator_to_array($results)[0]->match->stage)->toBeNull();
    });

    it('reports the stage of stage zero even when the limit hides its non-exact hits', function (): void {
        Order::create(['signature' => 'ABC', 'state' => 'shipped']);
        Order::create(['signature' => 'ABC-2', 'state' => 'unshipped']);

        $results = orderSearch('ABC')->limit(1)->get();

        expect(signaturesOf($results))->toBe(['ABC'])
            ->and($results->stage)->toBe('open');
    });

    it('decides whether stage zero has non-exact hits after each record keeps only its best hit', function (): void {
        Order::create(['signature' => 'ABC', 'external_number' => 'ABC-1', 'state' => 'unshipped']);

        $results = orderSearch('ABC')->get();

        expect(signaturesOf($results))->toBe(['ABC'])
            ->and($results->stage)->toBe('all')
            ->and(iterator_to_array($results)[0]->match->stage)->toBe('open');
    });

    it('reports no stage for a definition without stages', function (): void {
        Customer::create(['name' => 'Kowalski', 'email' => 'k@example.com']);

        $results = CustomerSearch::query('kowal')->within(Customer::query())->get();

        expect($results->stage)->toBeNull()
            ->and(iterator_to_array($results)[0]->match->stage)->toBeNull();
    });
});

describe('apply', function (): void {
    it('keeps pagination and count', function (): void {
        foreach (range(1, 7) as $i) {
            Order::create(['signature' => 'ABCD-'.$i]);
        }
        Order::create(['signature' => 'OTHER-1']);

        $table = orderSearch('ABCD')->apply(Order::query()->orderBy('signature'));
        $count = $table->count();
        $page = $table->paginate(3, page: 2);

        expect($count)->toBe(7)
            ->and($page->total())->toBe(7)
            ->and($page->pluck('signature')->all())->toBe(['ABCD-4', 'ABCD-5', 'ABCD-6']);
    });

    it('returns the same builder and leaves other conditions of the table alone', function (): void {
        Order::create(['signature' => 'ABCD-1', 'state' => 'shipped']);
        Order::create(['signature' => 'ABCD-2', 'state' => 'unshipped']);

        $table = Order::query()->where('state', 'unshipped');

        expect(orderSearch('ABCD')->apply($table))->toBe($table)
            ->and($table->pluck('signature')->all())->toBe(['ABCD-2']);
    });

    it('searches every stage and ignores the limit', function (): void {
        foreach (range(1, 8) as $i) {
            Order::create(['signature' => 'ABCD-'.$i, 'state' => $i % 2 === 0 ? 'shipped' : 'unshipped']);
        }

        expect(orderSearch('ABCD')->limit(2)->apply(Order::query())->count())->toBe(8);
    });

    it('keeps the phrase out of the table query', function (): void {
        Order::create(['signature' => 'secretphrase-1']);

        $table = orderSearch('secretphrase')->apply(Order::query());

        expect($table->toSql())->not->toContain('secretphrase')
            ->and(json_encode($table->getBindings()))->not->toContain('secretphrase')
            ->and($table->count())->toBe(1);
    });

    it('needs a longer prefix than the palette', function (): void {
        Order::create(['signature' => 'ABCD-1']);

        expect(orderSearch('ABC')->get())->toHaveCount(1)
            ->and(orderSearch('ABC')->apply(Order::query())->count())->toBe(0)
            ->and(orderSearch('ABCD')->apply(Order::query())->count())->toBe(1)
            ->and(orderSearch('ABCD-1')->apply(Order::query())->count())->toBe(1);
    });

    it('applies the scope of within() inside the id lookup', function (): void {
        Order::create(['signature' => 'ABCD-1', 'state' => 'shipped']);
        Order::create(['signature' => 'ABCD-2', 'state' => 'unshipped']);

        $table = OrderSearch::query('ABCD')->within(Order::query()->where('state', 'unshipped'))->apply(Order::query());

        expect($table->pluck('signature')->all())->toBe(['ABCD-2']);
    });

    it('survives an orWhere in the table query', function (): void {
        Order::create(['signature' => 'ABCD-1', 'state' => 'unshipped']);
        Order::create(['signature' => 'ABCD-2', 'state' => 'shipped']);
        Order::create(['signature' => 'ABCD-3', 'state' => 'cancelled', 'customer_email' => 'x']);
        Order::create(['signature' => 'OTHER-1', 'state' => 'unshipped']);

        $table = Order::query()->where('state', 'unshipped')->orWhere('state', 'shipped');
        $found = orderSearch('ABCD')->apply($table)->pluck('signature')->sort()->values()->all();

        expect($found)->toBe(['ABCD-1', 'ABCD-2']);
    });

    it('gives no rows, with pagination intact, when nothing matches', function (): void {
        Order::create(['signature' => 'ABCD-1']);

        $page = orderSearch('NOTHINGHERE')->apply(Order::query())->paginate(3);

        expect($page->total())->toBe(0)->and($page->items())->toBe([]);
    });

    it('can be applied twice', function (): void {
        Order::create(['signature' => 'ABCD-1', 'customer_email' => 'abcd@example.com']);
        Order::create(['signature' => 'ABCD-2']);

        $table = orderSearch('ABCD')->apply(Order::query());
        $table = orderSearch('ABCD-1')->apply($table);

        expect($table->pluck('signature')->all())->toBe(['ABCD-1']);
    });

    it('refuses a phrase that matches more than apply.max_ids records', function (): void {
        config(['model-search.apply.max_ids' => 2]);
        foreach (range(1, 3) as $i) {
            Order::create(['signature' => 'ABCD-'.$i]);
        }

        try {
            orderSearch('ABCD')->apply(Order::query());
            $this->fail('Expected SearchTooBroad.');
        } catch (SearchTooBroad $exception) {
            expect(stripos($exception->getMessage(), 'abcd'))->toBeFalse();
        }

        expect(orderSearch('ABCD-1')->apply(Order::query())->count())->toBe(1);
        config(['model-search.apply.max_ids' => 3]);
        expect(orderSearch('ABCD')->apply(Order::query())->count())->toBe(3);
    });

    it('rejects a table query of another model', function (): void {
        expect(fn () => orderSearch('ABCD')->apply(Customer::query()))->toThrow(InvalidArgumentException::class);
    });
});

describe('similar', function (): void {
    it('finds names by any part of a word, with no typo tolerance', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        Order::create(['signature' => 'S-2', 'customer_name' => 'Anna Nowak']);

        $results = orderSearch('walsk')->similar();

        expect(signaturesOf($results))->toBe(['S-1'])
            ->and(iterator_to_array($results)[0]->match->mode)->toBe(Mode::Fuzzy)
            ->and(iterator_to_array($results)[0]->match->kind)->toBe('customer_name')
            ->and(iterator_to_array($results)[0]->match->value)->toBe('Jan Kowalski')
            ->and(signaturesOf(orderSearch('kowalsky')->similar()))->toBe([])
            ->and(signaturesOf(orderSearch('ja ow')->similar()))->toBe([])
            ->and(signaturesOf(orderSearch('jan wal')->similar()))->toBe(['S-1']);
    });

    it('honours withoutStages()', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);

        $results = orderSearch('kowal')->withoutStages()->similar();

        expect(iterator_to_array($results)[0]->match->stage)->toBeNull();
    });

    it('looks in all stages and at names only', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski', 'state' => 'shipped']);
        Order::create(['signature' => 'Kowalski-9', 'customer_name' => 'Zed']);

        $results = orderSearch('kowalski')->similar();

        expect(signaturesOf($results))->toBe(['S-1'])
            ->and($results->stage)->toBeNull()
            ->and(iterator_to_array($results)[0]->match->stage)->toBe('all');
    });

    it('is not offered for an identifier-like phrase and respects the minimum length', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Kowalski 4455']);

        expect(signaturesOf(orderSearch('4455')->similar()))->toBe([])
            ->and(signaturesOf(orderSearch('ow')->similar()))->toBe([]);
    });

    it('applies the scope and the limit', function (): void {
        foreach (range(1, 4) as $i) {
            Order::create(['signature' => 'S-'.$i, 'customer_name' => 'Kowalski '.$i, 'state' => $i === 4 ? 'shipped' : 'unshipped']);
        }

        expect(signaturesOf(orderSearch('kowal')->limit(2)->similar()))->toHaveCount(2)
            ->and(signaturesOf(OrderSearch::query('kowal')->within(Order::query()->where('state', 'shipped'))->similar()))->toBe(['S-4']);
    });

    it('runs on definitions that have no name fields', function (): void {
        useOrderFields([SearchField::key('signature')]);
        Order::create(['signature' => 'S-1']);

        expect(signaturesOf(ConfigurableOrderSearch::query('kowal')->within(Order::query())->similar()))->toBe([]);
    });
});

describe('candidates', function (): void {
    it('returns raw candidates without the scope or hydration', function (): void {
        $hidden = Order::create(['signature' => 'ABC-1', 'state' => 'shipped', 'customer_email' => 'x']);

        $rows = OrderSearch::query('ABC-1')->withoutScope('support tooling')->candidates();

        expect($rows)->toHaveCount(1)
            ->and($rows->first())->toBe([
                'type' => 'order',
                'id' => $hidden->id,
                'mode' => Mode::Exact,
                'kind' => 'signature',
                'value' => 'ABC-1',
                'stage' => 'all',
            ]);
    });

    it('lists every field that matched, best first', function (): void {
        Order::create(['signature' => 'ABC-1', 'external_number' => 'ABC-1-X']);

        $rows = OrderSearch::query('ABC-1')->withoutScope('support tooling')->candidates();

        expect($rows->pluck('kind')->all())->toBe(['signature', 'external_number'])
            ->and($rows->pluck('mode')->all())->toBe([Mode::Exact, Mode::Prefix]);
    });
});

describe('time and privacy', function (): void {
    it('surfaces a timeout as timedOut, never as an empty result', function (): void {
        Order::create(['signature' => 'ABC-1']);
        config(['model-search.search.timeout_ms' => 50]);
        $slow = Order::query()->whereRaw('pg_sleep(0.4) is not null');

        $results = OrderSearch::query('ABC-1')->within($slow)->get();

        expect($results->timedOut)->toBeTrue()
            ->and(iterator_to_array($results))->toBe([]);
    });

    it('does not leave the timeout behind', function (): void {
        Order::create(['signature' => 'ABC-1']);
        $before = DB::scalar("select current_setting('statement_timeout')");

        orderSearch('ABC-1')->get();
        $slow = OrderSearch::query('ABC-1')->within(Order::query()->whereRaw('pg_sleep(0.3) is not null'));
        config(['model-search.search.timeout_ms' => 20]);
        $slow->get();

        expect(DB::scalar("select current_setting('statement_timeout')"))->toBe($before);
    });

    it('throws SearchTimedOut from apply, without the phrase', function (): void {
        Order::create(['signature' => 'secretphrase']);
        config(['model-search.search.timeout_ms' => 50]);
        $slow = Order::query()->whereRaw('pg_sleep(0.4) is not null');

        try {
            OrderSearch::query('secretphrase')->within($slow)->apply(Order::query());
            $this->fail('Expected a timeout.');
        } catch (SearchTimedOut $exception) {
            expect($exception->getMessage())->not->toContain('secretphrase')
                ->and($exception->getPrevious())->toBeNull();
        }
    });

    it('does not run similar() on the search timeout', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Kowalski']);
        config(['model-search.search.timeout_ms' => 20, 'model-search.similar.timeout_ms' => 2000]);
        $slow = Order::query()->whereRaw('pg_sleep(0.2) is not null');

        $results = OrderSearch::query('kowal')->within($slow)->similar();

        expect($results->timedOut)->toBeFalse()
            ->and(iterator_to_array($results))->toHaveCount(1);
    });

    it('keeps the phrase out of the message of a database failure and drops the original', function (): void {
        $broken = Order::query()->whereRaw('no_such_column = 1');
        $failures = [
            fn () => OrderSearch::query('secretphrase')->within($broken)->get(),
            fn () => OrderSearch::query('secretphrase')->within($broken)->similar(),
            fn () => OrderSearch::query('secretphrase')->within($broken)->apply(Order::query()),
        ];

        foreach ($failures as $failure) {
            try {
                $failure();
                $this->fail('Expected a failure.');
            } catch (SearchFailed $exception) {
                expect($exception->getMessage())->not->toContain('secretphrase')
                    ->and($exception->getPrevious())->toBeNull();
            }
        }
    });

    it('keeps the phrase out of the messages of its own exceptions', function (): void {
        $messages = [];

        foreach ([
            fn () => OrderSearch::query('secretphrase')->get(),
            fn () => OrderSearch::query('secretphrase')->within(Order::query()->limit(1)),
            fn () => OrderSearch::query('secretphrase')->withoutScope(' '),
            fn () => OrderSearch::query('secretphrase')->limit(0),
        ] as $attempt) {
            try {
                $attempt();
            } catch (Throwable $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        expect($messages)->toHaveCount(4)
            ->and(implode(' ', $messages))->not->toContain('secretphrase');
    });
});

describe('via', function (): void {
    function invoiceSearch(string $phrase, ?Builder $parents = null): SearchQuery
    {
        return InvoiceSearch::query($phrase)->within(Invoice::query(), $parents === null ? [] : [Order::class => $parents]);
    }

    it('finds a record through a parent that is in the parent scope', function (): void {
        $order = Order::create(['signature' => 'ORD-1']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $order->id]);
        Invoice::create(['number' => 'FV-2']);

        $results = invoiceSearch('ORD-1', Order::query())->get();
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

        expect(iterator_to_array(invoiceSearch('ORD-1')->get()))->toBe([])
            ->and(invoiceSearch('ORD-1')->apply(Invoice::query())->count())->toBe(0);
    });

    it('never surfaces a child through a parent outside the parent scope', function (): void {
        $order = Order::create(['signature' => 'ORD-1', 'state' => 'unshipped']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $order->id]);

        $results = invoiceSearch('ORD-1', Order::query()->where('state', 'shipped'))->get();

        expect(iterator_to_array($results))->toBe([]);
    });

    it('follows only the kinds the via field names', function (): void {
        $order = Order::create(['signature' => 'ORD-1', 'external_number' => 'EXT-9999']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $order->id]);

        expect(iterator_to_array(invoiceSearch('EXT-9999', Order::query())->get()))->toBe([]);
    });

    it('reports a record found both directly and through a parent once, as direct', function (): void {
        $order = Order::create(['signature' => 'ORD-1']);
        Invoice::create(['number' => 'ORD-1', 'order_id' => $order->id]);

        $results = iterator_to_array(invoiceSearch('ORD-1', Order::query())->get());

        expect($results)->toHaveCount(1)
            ->and($results[0]->match->via)->toBeNull()
            ->and($results[0]->match->kind)->toBe('number');
    });

    it('ranks a hit through a parent below a direct hit of the same mode', function (): void {
        $order = Order::create(['signature' => 'ORD-1']);
        Invoice::create(['number' => 'FV-VIA', 'order_id' => $order->id]);
        Invoice::create(['number' => 'ORD-1']);

        $results = iterator_to_array(invoiceSearch('ORD-1', Order::query())->get());

        expect(array_map(static fn (SearchResult $r): string => $r->model->number, $results))->toBe(['ORD-1', 'FV-VIA']);
    });

    it('is applied to a table too', function (): void {
        $order = Order::create(['signature' => 'ORD-1']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $order->id]);
        Invoice::create(['number' => 'FV-2']);

        expect(invoiceSearch('ORD-1', Order::query())->apply(Invoice::query())->pluck('number')->all())->toBe(['FV-1']);
    });

    it('rejects a parent scope for the wrong model', function (): void {
        expect(fn () => InvoiceSearch::query('x')->within(Invoice::query(), [Order::class => Customer::query()]))->toThrow(UnsupportedHostQuery::class)
            ->and(fn () => InvoiceSearch::query('x')->within(Invoice::query(), [Order::class => Order::query()->limit(1)]))->toThrow(UnsupportedHostQuery::class)
            ->and(fn () => InvoiceSearch::query('x')->within(Invoice::query(), ['NotAModel' => Order::query()]))->toThrow(UnsupportedHostQuery::class);
    });

    it('does not use the parent scope of a withoutScope() search', function (): void {
        $order = Order::create(['signature' => 'ORD-1']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $order->id]);

        $query = InvoiceSearch::query('ORD-1')->within(Invoice::query(), [Order::class => Order::query()])->withoutScope('system job');

        expect(iterator_to_array($query->get()))->toBe([]);
    });
});

describe('via parents', function (): void {
    function useRegistry(string ...$definitions): void
    {
        $registry = new ModelSearchRegistry;
        foreach ($definitions as $definition) {
            $registry->register($definition);
        }
        app()->instance(ModelSearchRegistry::class, $registry);
    }

    it('honours the owner key of the relation', function (): void {
        useRegistry(OwnerKeyInvoiceSearch::class, OrderSearch::class);
        $inScope = Order::create(['signature' => 'ORD-B', 'state' => 'unshipped']);
        $real = Order::create(['signature' => 'ORD-A', 'external_number' => $inScope->id, 'state' => 'shipped']);
        Invoice::create(['number' => 'FV-1', 'external_reference' => $inScope->id]);
        $scope = Order::query()->where('state', 'unshipped');

        $wrongParent = OwnerKeyInvoiceSearch::query('ORD-B')->within(Invoice::query(), [Order::class => $scope])->get();
        $realParent = OwnerKeyInvoiceSearch::query('ORD-A')->within(Invoice::query(), [Order::class => Order::query()])->get();

        expect($real->id)->not->toBe($inScope->id)
            ->and(iterator_to_array($wrongParent))->toBe([])
            ->and(iterator_to_array($realParent))->toHaveCount(1);
    });

    it('takes at most 50 parents per via path', function (): void {
        foreach (range(1, 60) as $i) {
            $order = Order::create(['signature' => sprintf('ORD-%02d', $i)]);
            Invoice::create(['number' => 'FV-'.$i, 'order_id' => $order->id]);
        }

        $results = invoiceSearch('ORD', Order::query())->limit(100)->get();

        expect(iterator_to_array($results))->toHaveCount(50);
    });

    it('fails loudly when the parent has no definition', function (): void {
        useRegistry(InvoiceSearch::class);

        expect(fn () => InvoiceSearch::query('ORD-1')->within(Invoice::query(), [Order::class => Order::query()])->get())->toThrow(InvalidSearchDefinition::class);
    });

    it('matches a scope given for a base class to its subclass', function (): void {
        $query = InvoiceSearch::query('x')->within(Invoice::query(), [Order::class => Order::query()]);

        expect($query->parentScope(Order::class))->not->toBeNull()
            ->and($query->parentScope(SubOrder::class))->not->toBeNull()
            ->and($query->parentScope(Customer::class))->toBeNull();
    });

    it('names its id column so it never shadows a real id', function (): void {
        Order::create(['signature' => 'ABCD-1']);

        $sql = orderSearch('ABCD')->apply(Order::query())->toSql();

        expect($sql)->toContain('"search_ids_0"."search_id"')->and($sql)->not->toContain('"search_ids_0"."id"');
    });
});

describe('viewer', function (): void {
    function viewerWithId(int $id): User
    {
        $user = new User;
        $user->forceFill(['id' => $id]);

        return $user;
    }

    it('lets forUser() decide the reveal even when another user is authenticated', function (): void {
        useOrderFields([
            SearchField::key('customer_email')->revealUsing(static fn ($order, $viewer): bool => $viewer?->getAuthIdentifier() === 7),
        ]);
        Order::create(['signature' => 'S-1', 'customer_email' => 'a@example.com']);
        $this->actingAs(viewerWithId(1));
        $value = static fn (SearchQuery $query): ?string => iterator_to_array($query->get())[0]->match->value;
        $base = static fn (): SearchQuery => ConfigurableOrderSearch::query('a@example.com')->within(Order::query());

        expect($value($base()))->toBeNull()
            ->and($value($base()->forUser(viewerWithId(7))))->toBe('a@example.com')
            ->and($value($base()->forUser(null)))->toBeNull();
        $this->actingAs(viewerWithId(7));
        expect($value($base()))->toBe('a@example.com')
            ->and($value($base()->forUser(viewerWithId(2))))->toBeNull();
    });
});
