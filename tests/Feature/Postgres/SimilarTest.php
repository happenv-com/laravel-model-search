<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Exceptions\SearchRateLimited;
use Happenv\ModelSearch\Exceptions\UnsupportedQuery;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Query\SimilarQuery;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Query\SearchResults;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;

covers(SimilarQuery::class);

beforeEach(function (): void {
    config(['model-search.driver' => 'postgres']);
});

afterEach(fn () => ConfigurableOrderSearch::reset());

function smSimilar(string $phrase): SearchResults
{
    return OrderSearch::query($phrase)->within(Order::query())->similar();
}

describe('matching', function (): void {
    it('similar finds a one-typo surname', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        Order::create(['signature' => 'S-2', 'customer_name' => 'Anna Nowak']);
        msIndex();

        $results = smSimilar('Kowalsky');
        $match = iterator_to_array($results)[0]->match;

        expect(msSignatures($results))->toBe(['S-1'])
            ->and($match->mode)->toBe(Mode::Fuzzy)
            ->and($match->kind)->toBe('customer_name')
            ->and($match->label)->toBe('Customer Name')
            ->and($match->value)->toBe('Jan Kowalski')
            ->and(msSignatures(smSimilar('Kowalsky Jan')))->toBe(['S-1'])
            ->and(msSignatures(smSimilar('Zielinski')))->toBe([]);
    });

    it('ranks the closest name first, then the newest', function (): void {
        ConfigurableOrderSearch::$sortAt = 'purchased_at';
        msUse([SearchField::name('customer_name')]);
        Order::create(['signature' => 'S-near', 'customer_name' => 'Kowalska', 'purchased_at' => '2026-01-01']);
        Order::create(['signature' => 'S-exact', 'customer_name' => 'Kowalski', 'purchased_at' => '2026-01-01']);
        Order::create(['signature' => 'S-new', 'customer_name' => 'Kowalska', 'purchased_at' => '2026-03-01']);
        msIndex();

        expect(msSignatures(ConfigurableOrderSearch::query('Kowalski')->within(Order::query())->similar()))->toBe(['S-exact', 'S-new', 'S-near']);
    });

    it('looks in all stages and reports the stage of each record', function (): void {
        Order::create(['signature' => 'S-open', 'customer_name' => 'Jan Kowalski', 'state' => 'unshipped']);
        Order::create(['signature' => 'S-shipped', 'customer_name' => 'Jan Kowalski', 'state' => 'shipped']);
        msIndex();

        $results = smSimilar('Kowalsky');
        $stages = [];

        foreach ($results as $result) {
            $stages[$result->model->signature] = $result->match->stage;
        }

        expect($stages)->toBe(['S-open' => 'open', 'S-shipped' => 'all'])
            ->and($results->stage)->toBeNull();
    });

    it('honours withoutStages()', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        msIndex();

        expect(iterator_to_array(OrderSearch::query('Kowalsky')->within(Order::query())->withoutStages()->similar())[0]->match->stage)->toBeNull();
    });

    it('is not offered for an identifier-like phrase and respects the minimum length', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Kowalski 4455', 'customer_email' => 'kowalski@example.com']);
        msIndex();
        $recorder = msRecorder();

        expect(msSignatures(smSimilar('4455')))->toBe([])
            ->and(msSignatures(smSimilar('kowalski@example.com')))->toBe([])
            ->and(msSignatures(smSimilar('ow')))->toBe([])
            ->and(msSignatures(smSimilar('日本語')))->toBe([])
            ->and($recorder->log())->toBe([]);
    });

    it('applies the scope and the limit after the scope', function (): void {
        foreach (range(1, 4) as $i) {
            Order::create(['signature' => 'S-'.$i, 'customer_name' => 'Kowalski '.$i, 'state' => $i === 4 ? 'shipped' : 'unshipped']);
        }
        msIndex();

        expect(msSignatures(OrderSearch::query('kowalsky')->within(Order::query())->limit(2)->similar()))->toHaveCount(2)
            ->and(msSignatures(OrderSearch::query('kowalsky')->within(Order::query()->where('state', 'shipped'))->limit(1)->similar()))->toBe(['S-4']);
    });

    it('runs on definitions that have no name fields', function (): void {
        msUse([SearchField::key('signature')]);
        Order::create(['signature' => 'S-1']);
        msIndex();
        $recorder = msRecorder();

        expect(msSignatures(ConfigurableOrderSearch::query('kowalsky')->within(Order::query())->similar()))->toBe([])
            ->and($recorder->log())->toBe([]);
    });

    it('does not look at name fields that did not declare Fuzzy', function (): void {
        msUse([SearchField::name('customer_name')->matches(Mode::Prefix)]);
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        msIndex();

        expect(msSignatures(ConfigurableOrderSearch::query('kowalsky')->within(Order::query())->similar()))->toBe([]);
    });

    it('leaves the keys alone', function (): void {
        Order::create(['signature' => 'Kowalski-9', 'customer_name' => 'Zed']);
        msIndex();

        expect(msSignatures(smSimilar('kowalski')))->toBe([]);
    });
});

describe('reveal and only', function (): void {
    it('similar respects within() and revealUsing', function (): void {
        msUse([
            SearchField::name('customer_name')->revealUsing(static fn ($order, $viewer): bool => $viewer?->getAuthIdentifier() === 7),
        ]);
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski', 'state' => 'shipped']);
        Order::create(['signature' => 'S-2', 'customer_name' => 'Jan Kowalski', 'state' => 'unshipped']);
        msIndex();
        $viewer = new User;
        $viewer->forceFill(['id' => 7]);
        $query = static fn (): SearchQuery => ConfigurableOrderSearch::query('kowalsky')->within(Order::query()->where('state', 'shipped'));

        $hidden = iterator_to_array($query()->similar());
        $shown = iterator_to_array($query()->forUser($viewer)->similar());

        expect($hidden)->toHaveCount(1)
            ->and($hidden[0]->model->signature)->toBe('S-1')
            ->and($hidden[0]->match->value)->toBeNull()
            ->and($hidden[0]->match->label)->not->toBe('')
            ->and($shown[0]->match->value)->toBe('Jan Kowalski');
    });

    it('refuses a strict subset of the name fields, on both drivers', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        msIndex();
        $subset = static fn () => OrderSearch::query('kowalsky')->within(Order::query())->only(['customer_name'])->similar();

        expect($subset)->toThrow(UnsupportedQuery::class);

        config(['model-search.driver' => 'database']);

        expect($subset)->toThrow(UnsupportedQuery::class)
            ->and(msSignatures(OrderSearch::query('kowal')->within(Order::query())->only(['customer_name', 'shipping_name'])->similar()))->toBe(['S-1'])
            ->and(msSignatures(OrderSearch::query('kowal')->within(Order::query())->only(['signature'])->similar()))->toBe([]);
    });
});

describe('the session', function (): void {
    it('runs one statement on the index and keeps the phrase off the host', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Secretphrase Person']);
        msIndex();
        $heard = [];
        DB::listen(static function ($query) use (&$heard): void {
            $heard[] = $query->sql.json_encode($query->bindings);
        });
        $recorder = msRecorder();

        $results = smSimilar('secretphrasx');

        expect(msSignatures($results))->toBe(['S-1'])
            ->and(msIndexStatements($recorder))->toHaveCount(1)
            ->and(stripos(implode('', $heard), 'secretphras'))->toBeFalse();
    });

    it('charges the rate limit once', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        msIndex();
        config(['model-search.rate_limit' => ['max_attempts' => 1, 'decay_seconds' => 60]]);
        $viewer = new User;
        $viewer->forceFill(['id' => 21]);
        $query = static fn () => OrderSearch::query('kowalsky')->within(Order::query())->forUser($viewer)->similar();

        expect(count($query()))->toBe(1)
            ->and($query)->toThrow(SearchRateLimited::class);
    });

    it('similar uses its own timeout', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        msIndex();
        config(['model-search.search.timeout_ms' => 20, 'model-search.similar.timeout_ms' => 2000]);
        $slow = Order::query()->whereRaw('pg_sleep(0.15) is not null');

        $similar = OrderSearch::query('kowalsky')->within($slow)->similar();
        $get = OrderSearch::query('kowal')->within($slow)->get();

        expect($similar->timedOut)->toBeFalse()
            ->and(msSignatures($similar))->toBe(['S-1'])
            ->and($get->timedOut)->toBeTrue();
    });

    it('reports a timeout as timedOut, never as an empty result', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        msIndex();
        config(['model-search.similar.timeout_ms' => 50]);
        $slow = Order::query()->whereRaw('pg_sleep(0.4) is not null');

        $results = OrderSearch::query('kowalsky')->within($slow)->similar();

        expect($results->timedOut)->toBeTrue()
            ->and(iterator_to_array($results))->toBe([]);
    });

    it('uses the threshold of the configuration', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        msIndex();

        config(['model-search.word_similarity_threshold' => 0.95]);
        $strict = msSignatures(smSimilar('kowalsky'));
        config(['model-search.word_similarity_threshold' => 0.3]);
        $loose = msSignatures(smSimilar('kowalsky'));

        expect($strict)->toBe([])
            ->and($loose)->toBe(['S-1']);
    });
});

describe('readiness', function (): void {
    function smUnready(): void
    {
        DB::table(TableNames::forConnection()->state())->where('subject_type', 'order')->update(['ready_at' => null]);
        (new IndexState)->forget('order');
    }

    it('falls back to the database driver until the index is ready, and says it is building', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        smUnready();
        $recorder = msRecorder();

        $results = smSimilar('walsk');

        expect(msSignatures($results))->toBe(['S-1'])
            ->and($results->indexBuilding)->toBeTrue()
            ->and(msIndexStatements($recorder))->toBe([]);
    });

    it('answers nothing, and says so, for a type without a fallback', function (): void {
        ConfigurableOrderSearch::$fallback = false;
        msUse([SearchField::name('customer_name')]);
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        smUnready();
        $recorder = msRecorder();

        $results = ConfigurableOrderSearch::query('kowalsky')->within(Order::query())->similar();

        expect(iterator_to_array($results))->toBe([])
            ->and($results->indexBuilding)->toBeTrue()
            ->and($recorder->log())->toBe([]);
    });

    it('does not say the index is building when it is ready', function (): void {
        Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
        msIndex();

        expect(smSimilar('kowalsky')->indexBuilding)->toBeFalse();
    });
});
