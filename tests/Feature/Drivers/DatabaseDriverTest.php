<?php

declare(strict_types=1);

use Happenv\ModelSearch\Drivers\DatabaseDriver;
use Happenv\ModelSearch\Postgres\Session\SearchConnectionFactory;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Session\RecordingSearchConnectionFactory;
use Illuminate\Support\Facades\DB;

covers(DatabaseDriver::class);

beforeEach(function (): void {
    config(['model-search.driver' => 'database']);
});

/**
 * Records what the search connection runs: the host's own log must never see it.
 */
function ddRecord(): RecordingSearchConnectionFactory
{
    $factory = new RecordingSearchConnectionFactory;
    app()->instance(SearchConnectionFactory::class, $factory);

    return $factory;
}

it('runs the search in one statement per pass', function (): void {
    Order::create(['signature' => 'ABC-1', 'state' => 'unshipped']);
    $recorder = ddRecord();

    OrderSearch::query('ABC')->within(Order::query())->get();

    // One search statement; the stage statement is part of it, and hydration runs on the host.
    expect($recorder->searches())->toHaveCount(1);
});

it('puts the stage zero restriction in the first pass only', function (): void {
    Order::create(['signature' => 'ABC-1', 'state' => 'shipped']);
    $recorder = ddRecord();

    OrderSearch::query('ABC')->within(Order::query())->get();
    $searches = $recorder->searches();

    expect($searches)->toHaveCount(2)
        ->and($searches[0]['query'])->toContain('(h."level" % 10 = 0 OR EXISTS')
        ->and($searches[1]['query'])->not->toContain('(h."level" % 10 = 0 OR EXISTS');
});

it('sets the statement timeout as a literal inside the transaction', function (): void {
    config(['model-search.search.timeout_ms' => 250]);
    Order::create(['signature' => 'ABC-1']);
    $recorder = ddRecord();

    OrderSearch::query('ABC')->within(Order::query())->get();

    expect(array_column($recorder->log(), 'query'))->toContain('SET LOCAL statement_timeout = 250');
});

it('keeps the phrase to bound parameters of the search statement', function (): void {
    Order::create(['signature' => 'ABCDEF']);
    $recorder = ddRecord();

    OrderSearch::query('ABCDEF')->within(Order::query())->get();
    $search = $recorder->searches()[0];

    expect(stripos($search['query'], 'abcdef'))->toBeFalse()
        ->and(json_encode($search['bindings']))->toContain('abcdef');
});

it('keeps the phrase away from the hosts query log and listeners', function (): void {
    Order::create(['signature' => 'ABCDEF']);
    $heard = [];
    DB::listen(static function ($query) use (&$heard): void {
        $heard[] = $query->sql.json_encode($query->bindings);
    });
    DB::enableQueryLog();

    OrderSearch::query('ABCDEF')->within(Order::query())->get();
    OrderSearch::query('ABCDEF')->within(Order::query())->similar();
    OrderSearch::query('ABCDEF')->within(Order::query())->apply(Order::query());
    OrderSearch::query('ABCDEF')->withoutScope('test')->candidates();
    $logged = json_encode(DB::getQueryLog());
    DB::disableQueryLog();

    expect(stripos($logged.implode('', $heard), 'abcdef'))->toBeFalse();
});
