<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Drivers\DriverManager;
use Happenv\ModelSearch\Events\SearchPerformed;
use Happenv\ModelSearch\Exceptions\SearchIndexNotReady;
use Happenv\ModelSearch\Exceptions\SearchRateLimited;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Happenv\ModelSearch\Exceptions\SearchTooBroad;
use Happenv\ModelSearch\Exceptions\UnsupportedQuery;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Query\ApplyIds;
use Happenv\ModelSearch\Postgres\Query\PostgresDriver;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\InvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

covers(ApplyIds::class);

beforeEach(function (): void {
    config(['model-search.driver' => 'postgres']);
});

afterEach(fn () => ConfigurableOrderSearch::reset());

/**
 * @param  Builder<Order>  $table
 * @return list<string>
 */
function apSignatures(Builder $table): array
{
    $signatures = $table->pluck('signature')->all();
    sort($signatures);

    return $signatures;
}

function apApply(string $phrase, ?Builder $table = null): Builder
{
    return OrderSearch::query($phrase)->within(Order::query())->apply($table ?? Order::query());
}

describe('narrowing a table', function (): void {
    it('apply keeps pagination and count', function (): void {
        foreach (range(1, 7) as $i) {
            Order::create(['signature' => 'ABCD-'.$i]);
        }
        Order::create(['signature' => 'OTHER-1']);
        msIndex();

        $table = apApply('ABCD')->orderBy('signature');

        expect($table->count())->toBe(7)
            ->and($table->paginate(3)->total())->toBe(7)
            ->and($table->paginate(3, page: 3)->items())->toHaveCount(1)
            ->and($table->simplePaginate(5)->items())->toHaveCount(5)
            ->and(apSignatures(apApply('ABCD')))->toBe(['ABCD-1', 'ABCD-2', 'ABCD-3', 'ABCD-4', 'ABCD-5', 'ABCD-6', 'ABCD-7']);
    });

    it('stays narrowed under a top-level orWhere of the table query', function (): void {
        Order::create(['signature' => 'ABCD-1', 'state' => 'unshipped']);
        Order::create(['signature' => 'ABCD-2', 'state' => 'shipped']);
        Order::create(['signature' => 'OTHER-1', 'state' => 'shipped']);
        msIndex();

        $table = apApply('ABCD', Order::query()->where('state', 'unshipped')->orWhere('state', 'cancelled'));

        expect(apSignatures($table))->toBe(['ABCD-1']);
    });

    it('answers an empty table for a phrase that matches nothing, and runs nothing for one that normalizes to nothing', function (): void {
        Order::create(['signature' => 'ABCD-1']);
        msIndex();
        $recorder = msRecorder();

        expect(apSignatures(apApply('ZZZZZZ')))->toBe([])
            ->and(count(msIndexStatements($recorder)))->toBe(1);

        $empty = msRecorder();

        expect(apSignatures(apApply('日本語')))->toBe([])
            ->and($empty->log())->toBe([]);
    });

    it('reads keys, name words and the source column', function (): void {
        msUse([
            SearchField::key('external_number'),
            SearchField::key('signature')->exactOnSource(),
            SearchField::name('customer_name'),
        ]);
        Order::create(['signature' => 'S-1', 'external_number' => 'EXT-12345', 'customer_name' => 'Jan Kowalski']);
        Order::create(['signature' => 'S-2', 'external_number' => 'OTHER-1', 'customer_name' => 'Anna Nowak']);
        msIndex();
        Order::create(['signature' => 'FRESH-ONE']);
        $apply = static fn (string $phrase): array => apSignatures(ConfigurableOrderSearch::query($phrase)->within(Order::query())->apply(Order::query()));

        expect($apply('EXT-1234'))->toBe(['S-1'])
            ->and($apply('12345'))->toBe(['S-1'])
            ->and($apply('345'))->toBe([])
            ->and($apply('kowal'))->toBe(['S-1'])
            ->and($apply('FRESH-ONE'))->toBe(['FRESH-ONE']);
    });

    it('takes prefixes and name words from four characters', function (): void {
        Order::create(['signature' => 'ABC-1', 'customer_name' => 'Kowalski']);
        Order::create(['signature' => 'S-2', 'customer_name' => 'Kowalczyk']);
        msIndex();

        expect(apSignatures(apApply('ABC')))->toBe([])
            ->and(apSignatures(apApply('ABC-1')))->toBe(['ABC-1'])
            ->and(apSignatures(apApply('kow')))->toBe([])
            ->and(apSignatures(apApply('kowa')))->toBe(['ABC-1', 'S-2'])
            ->and(apSignatures(apApply('kowals')))->toBe(['ABC-1']);
    });

    it('apply never runs Fuzzy', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        msIndex();
        $recorder = msRecorder();

        $typo = apSignatures(apApply('kowalsky'));
        $sql = implode("\n", array_column(msIndexStatements($recorder), 'query'));

        expect($typo)->toBe([])
            ->and(count(msIndexStatements($recorder)))->toBe(1)
            ->and(OrderSearch::query('kowalsky')->within(Order::query())->similar())->toHaveCount(1)
            ->and($sql)->not->toContain('word_similarity')
            ->and($sql)->not->toContain('<%');
    });

    it('respects only() and using()', function (): void {
        Order::create(['signature' => 'ABCD-1', 'external_number' => 'XYZ-1']);
        Order::create(['signature' => 'ZZZ-OTHER', 'external_number' => 'ABCD-2']);
        msIndex();
        $apply = static fn (Closure $change): array => apSignatures($change(OrderSearch::query('ABCD')->within(Order::query()))->apply(Order::query()));

        expect($apply(static fn (SearchQuery $q): SearchQuery => $q))->toBe(['ABCD-1', 'ZZZ-OTHER'])
            ->and($apply(static fn (SearchQuery $q): SearchQuery => $q->only(['signature'])))->toBe(['ABCD-1'])
            ->and($apply(static fn (SearchQuery $q): SearchQuery => $q->using(Mode::Exact)))->toBe([]);
    });

    it('refuses a strict subset of the name fields', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Kowalski']);
        msIndex();

        expect(fn () => apApply('kowal')->count())->not->toThrow(UnsupportedQuery::class)
            ->and(fn () => OrderSearch::query('kowal')->within(Order::query())->only(['customer_name'])->apply(Order::query()))->toThrow(UnsupportedQuery::class);
    });

    it('narrows in the partition of the query', function (): void {
        config(['model-search.partition_column' => 'tenant_id']);
        Order::create(['signature' => 'ABCD-1', 'tenant_id' => 1]);
        Order::create(['signature' => 'ABCD-2', 'tenant_id' => 2]);
        msIndex();
        Order::create(['signature' => 'FRESH-A', 'tenant_id' => 1]);
        Order::create(['signature' => 'FRESH-B', 'tenant_id' => 2]);
        $apply = static fn (string $phrase, int $tenant): array => apSignatures(OrderSearch::query($phrase)->within(Order::query())->partition($tenant)->apply(Order::query()));

        expect($apply('ABCD', 1))->toBe(['ABCD-1'])
            ->and($apply('ABCD', 2))->toBe(['ABCD-2'])
            ->and($apply('FRESH-A', 1))->toBe(['FRESH-A'])
            ->and($apply('FRESH-A', 2))->toBe([]);
    });
});

describe('via', function (): void {
    function apInvoices(string $phrase, ?Builder $parents = null): Builder
    {
        return InvoiceSearch::query($phrase)->within(Invoice::query(), $parents === null ? [] : [Order::class => $parents])->apply(Invoice::query());
    }

    it('apply via filters parents by the parent scope', function (): void {
        $shipped = Order::create(['signature' => 'ORDS-1', 'state' => 'shipped']);
        $open = Order::create(['signature' => 'ORDS-2', 'state' => 'unshipped']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $shipped->id]);
        Invoice::create(['number' => 'FV-2', 'order_id' => $open->id]);
        msIndex();

        $numbers = static fn (Builder $table): array => $table->orderBy('number')->pluck('number')->all();

        expect($numbers(apInvoices('ORDS', Order::query()->where('state', 'unshipped'))))->toBe(['FV-2'])
            ->and($numbers(apInvoices('ORDS', Order::query())))->toBe(['FV-1', 'FV-2'])
            ->and($numbers(apInvoices('ORDS')))->toBe([]);
    });

    it('filters the parents before the cap of fifty per path', function (): void {
        foreach (range(1, 60) as $i) {
            $order = Order::create(['signature' => sprintf('ORDS-%02d', $i), 'state' => 'shipped']);
            Invoice::create(['number' => 'FV-OUT-'.$i, 'order_id' => $order->id]);
        }
        $inScope = Order::create(['signature' => 'ORDS-ZZ', 'state' => 'unshipped']);
        Invoice::create(['number' => 'FV-IN', 'order_id' => $inScope->id]);
        msIndex();

        expect(apInvoices('ORDS', Order::query()->where('state', 'unshipped'))->pluck('number')->all())->toBe(['FV-IN']);
    });
});

describe('via overflow', function (): void {
    it('refuses a via path with more parents than its cap instead of returning a cut list, on both drivers', function (): void {
        foreach (range(1, 60) as $i) {
            $order = Order::create(['signature' => sprintf('ORDS-%02d', $i)]);
            Invoice::create(['number' => 'FV-'.$i, 'order_id' => $order->id]);
        }
        msIndex();
        $fifty = Order::query()->where('signature', '<=', 'ORDS-50');

        foreach (['postgres', 'database'] as $driver) {
            config(['model-search.driver' => $driver]);

            $fifty_one = Order::query()->where('signature', '<=', 'ORDS-51');
            $refusal = null;

            try {
                apInvoices('ORDS', $fifty_one);
            } catch (SearchTooBroad $exception) {
                $refusal = $exception->getMessage();
            }

            expect(fn () => apInvoices('ORDS', Order::query()), $driver)->toThrow(SearchTooBroad::class, 'parent records in scope')
                ->and($refusal, $driver)->toContain('50 parent records')
                ->and($refusal, $driver)->not->toContain('ORDS')
                ->and(apInvoices('ORDS', $fifty)->count(), $driver)->toBe(50);
        }
    });
});

describe('too broad', function (): void {
    it('apply beyond max ids throws SearchTooBroad', function (): void {
        config(['model-search.apply.max_ids' => 3]);
        foreach (range(1, 5) as $i) {
            Order::create(['signature' => 'SECRETPHRASE-'.$i]);
        }
        msIndex();

        try {
            apApply('SECRETPHRASE');
            $this->fail('Expected SearchTooBroad.');
        } catch (SearchTooBroad $exception) {
            expect($exception->getMessage())->not->toContain('SECRETPHRASE')
                ->and(stripos($exception->getMessage(), 'secretphrase'))->toBeFalse();
        }
    });

    it('accepts exactly max ids records', function (): void {
        config(['model-search.apply.max_ids' => 3]);
        foreach (range(1, 3) as $i) {
            Order::create(['signature' => 'ABCD-'.$i]);
        }
        msIndex();

        expect(apSignatures(apApply('ABCD')))->toHaveCount(3);
    });

    it('a branch reaching its cap is too broad', function (): void {
        config(['model-search.apply.max_ids' => 3]);
        msUse([
            SearchField::key('signature'),
            SearchField::key('external_number'),
            SearchField::key('customer_email')->kind('mail'),
            SearchField::key('customer_name')->kind('code'),
        ]);
        // One record, four keys of one branch: the branch hits its limit of four rows although only one record matches.
        Order::create(['signature' => 'ABCD-1', 'external_number' => 'ABCD-2', 'customer_email' => 'abcd-3@x.test', 'customer_name' => 'abcd4']);
        msIndex();

        expect(fn () => ConfigurableOrderSearch::query('ABCD')->within(Order::query())->apply(Order::query()))->toThrow(SearchTooBroad::class);
    });
});

describe('the session', function (): void {
    it('reports how many records matched, once', function (): void {
        Order::create(['signature' => 'ABCD-1']);
        Order::create(['signature' => 'ABCD-2']);
        msIndex();
        $performed = [];
        Event::listen(SearchPerformed::class, static function (SearchPerformed $event) use (&$performed): void {
            $performed[] = $event;
        });

        apApply('ABCD');

        expect($performed)->toHaveCount(1)
            ->and($performed[0]->resultCount)->toBe(2);
    });

    it('charges the rate limit once per call', function (): void {
        Order::create(['signature' => 'ABCD-1']);
        msIndex();
        config(['model-search.rate_limit' => ['max_attempts' => 2, 'decay_seconds' => 60]]);
        $viewer = new User;
        $viewer->forceFill(['id' => 31]);

        $first = OrderSearch::query('ABCD')->within(Order::query())->forUser($viewer)->apply(Order::query());
        $second = OrderSearch::query('ABCD')->within(Order::query())->forUser($viewer)->apply(Order::query());

        expect($first->count())->toBe(1)
            ->and($second->count())->toBe(1)
            ->and(fn () => OrderSearch::query('ABCD')->within(Order::query())->forUser($viewer)->apply(Order::query()))->toThrow(SearchRateLimited::class);
    });

    it('resolves the keys in one statement on the search connection, never with the phrase on the host', function (): void {
        Order::create(['signature' => 'SECRETPHRASE-1']);
        msIndex();
        $heard = [];
        DB::listen(static function ($query) use (&$heard): void {
            $heard[] = $query->sql.json_encode($query->bindings);
        });
        $recorder = msRecorder();

        $table = apApply('SECRETPHRASE');
        $count = $table->count();

        expect($count)->toBe(1)
            ->and(msIndexStatements($recorder))->toHaveCount(1)
            ->and(stripos(implode('', $heard), 'secretphrase'))->toBeFalse();
    });

    it('lets a timeout through as SearchTimedOut', function (): void {
        $order = Order::create(['signature' => 'ORD-1']);
        Invoice::create(['number' => 'FV-1', 'order_id' => $order->id]);
        msIndex();
        config(['model-search.search.timeout_ms' => 50]);
        $slowParents = Order::query()->whereRaw('pg_sleep(0.4) is not null');

        expect(fn () => InvoiceSearch::query('ORD-1')->within(Invoice::query(), [Order::class => $slowParents])->apply(Invoice::query()))->toThrow(SearchTimedOut::class);
    });
});

describe('the models apply hands back', function (): void {
    it('carry no search_id attribute, and keep a column of the table that has that name', function (): void {
        Order::create(['signature' => 'ABCD-1']);
        Schema::table('orders', static fn (Blueprint $table) => $table->text('search_id')->nullable());
        Order::query()->update(['search_id' => 'mine']);
        $plain = apApply('ABCD-1');

        expect($plain->first()->getAttributes())->toHaveKey('search_id')
            ->and($plain->first()->search_id)->toBe('mine');

        Schema::table('orders', static fn (Blueprint $table) => $table->dropColumn('search_id'));

        expect(array_keys(apApply('ABCD-1')->first()->getAttributes()))->not->toContain('search_id');
    });

    it('keep the columns the table query chose', function (): void {
        Order::create(['signature' => 'ABCD-1']);

        expect(array_keys(apApply('ABCD-1', Order::query()->select('signature'))->first()->getAttributes()))->toBe(['signature']);
    });
});

describe('the scope of apply', function (): void {
    it('returns the same rows on every path: table query, within() scope and matches', function (string $path): void {
        Order::create(['signature' => 'ABCD-1', 'state' => 'shipped']);
        Order::create(['signature' => 'ABCD-2', 'state' => 'unshipped']);
        Order::create(['signature' => 'ABCD-3', 'state' => 'shipped', 'customer_name' => 'x']);
        Order::create(['signature' => 'OTHER-1', 'state' => 'shipped']);
        msIndex();

        if ($path === 'database') {
            config(['model-search.driver' => 'database']);
        }

        if ($path === 'fallback') {
            apUnready();
        }

        $scoped = static fn (): Builder => OrderSearch::query('ABCD')->within(Order::query()->where('state', 'shipped'))->apply(Order::query());
        $tableNarrowed = OrderSearch::query('ABCD')->within(Order::query()->where('state', 'shipped'))->apply(Order::query()->where('signature', '<>', 'ABCD-3'));
        $unscoped = OrderSearch::query('ABCD')->withoutScope('test')->apply(Order::query());

        expect(apSignatures($scoped()))->toBe(['ABCD-1', 'ABCD-3'])
            ->and(apSignatures($tableNarrowed))->toBe(['ABCD-1'])
            ->and(apSignatures($unscoped))->toBe(['ABCD-1', 'ABCD-2', 'ABCD-3']);
    })->with(['postgres ready', 'fallback', 'database']);

    it('keeps the scope when the table query has an orWhere, and keeps count and pagination right', function (string $path): void {
        Order::create(['signature' => 'ABCD-1', 'state' => 'shipped']);
        Order::create(['signature' => 'ABCD-2', 'state' => 'unshipped']);
        Order::create(['signature' => 'ABCD-3', 'state' => 'shipped']);
        Order::create(['signature' => 'OTHER-1', 'state' => 'shipped']);
        msIndex();

        if ($path === 'database') {
            config(['model-search.driver' => 'database']);
        }

        if ($path === 'fallback') {
            apUnready();
        }

        $apply = static fn (): Builder => OrderSearch::query('ABCD')->within(Order::query()->where('state', 'shipped'))
            ->apply(Order::query()->where('state', 'unshipped')->orWhere('state', 'shipped'));

        expect(apSignatures($apply()))->toBe(['ABCD-1', 'ABCD-3'])
            ->and($apply()->count())->toBe(2)
            ->and($apply()->orderBy('signature')->paginate(1)->total())->toBe(2);
    })->with(['postgres ready', 'fallback', 'database']);

    it('treats an explicit select of everything like no columns', function (): void {
        Order::create(['signature' => 'ABCD-1']);
        msIndex();

        $models = OrderSearch::query('ABCD-1')->within(Order::query())->apply(Order::query()->select('*'))->get();

        expect(array_keys($models->first()->getAttributes()))->not->toContain('search_id');
    });
});

describe('readiness', function (): void {
    function apUnready(): void
    {
        DB::table(TableNames::forConnection()->state())->where('subject_type', 'order')->update(['ready_at' => null]);
        (new IndexState)->forget('order');
    }

    it('falls back to the source tables until the index is ready', function (): void {
        Order::create(['signature' => 'ABCD-1']);
        Order::create(['signature' => 'OTHER-1']);
        apUnready();
        $recorder = msRecorder();

        expect(apSignatures(apApply('ABCD')))->toBe(['ABCD-1'])
            ->and(msIndexStatements($recorder))->toBe([]);
    });

    it('refuses to apply, and runs nothing, for a type without a fallback whose index is not ready', function (): void {
        ConfigurableOrderSearch::$fallback = false;
        msUse([SearchField::key('signature')->exactOnSource(), SearchField::key('external_number')]);
        Order::create(['signature' => 'ABCD-1', 'external_number' => 'ABCD-1']);
        apUnready();
        $recorder = msRecorder();
        $apply = static fn (string $phrase): Builder => ConfigurableOrderSearch::query($phrase)->within(Order::query())->apply(Order::query());

        expect(fn () => $apply('ABCD-1'))->toThrow(SearchIndexNotReady::class)
            ->and($recorder->log())->toBe([]);

        try {
            $apply('ABCD-1');
        } catch (SearchIndexNotReady $refusal) {
            expect($refusal->getMessage())->not->toContain('ABCD-1');
        }
    });

    it('refuses to list the candidates of a type without a fallback whose index is not ready', function (): void {
        ConfigurableOrderSearch::$fallback = false;
        msUse([SearchField::key('signature')->exactOnSource(), SearchField::key('external_number')]);
        Order::create(['signature' => 'ABCD-1', 'external_number' => 'ABCD-1']);
        apUnready();

        expect(fn () => ConfigurableOrderSearch::query('ABCD-1')->withoutScope('test')->candidates())->toThrow(SearchIndexNotReady::class);
    });
});

it('is the postgres driver that serves apply', function (): void {
    expect(resolve(DriverManager::class)->for(app(ModelSearchRegistry::class)->for(Order::class)))->toBeInstanceOf(PostgresDriver::class);
});
