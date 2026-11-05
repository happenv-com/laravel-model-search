<?php

declare(strict_types=1);

use Happenv\ModelSearch\Drivers\DatabaseDriver;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Support\Facades\DB;

covers(DatabaseDriver::class);

beforeEach(function (): void {
    config(['model-search.driver' => 'database']);
});

it('runs the search in one statement per pass', function (): void {
    Order::create(['signature' => 'ABC-1', 'state' => 'unshipped']);
    DB::enableQueryLog();

    OrderSearch::query('ABC')->within(Order::query())->get();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $selects = array_values(array_filter($log, static fn (array $entry): bool => str_starts_with(strtolower($entry['query']), 'select')));

    // One search statement, one hydration; the stage statement is part of the search.
    expect(array_filter($selects, static fn (array $e): bool => str_contains($e['query'], 'DISTINCT ON')))->toHaveCount(1);
});

it('puts the stage zero restriction in the first pass only', function (): void {
    Order::create(['signature' => 'ABC-1', 'state' => 'shipped']);
    DB::enableQueryLog();

    OrderSearch::query('ABC')->within(Order::query())->get();
    $searches = array_values(array_filter(DB::getQueryLog(), static fn (array $e): bool => str_contains($e['query'], 'DISTINCT ON')));
    DB::disableQueryLog();

    expect($searches)->toHaveCount(2)
        ->and($searches[0]['query'])->toContain('(h."level" % 10 = 0 OR EXISTS')
        ->and($searches[1]['query'])->not->toContain('(h."level" % 10 = 0 OR EXISTS');
});

it('sets the statement timeout as a literal inside the transaction', function (): void {
    config(['model-search.search.timeout_ms' => 250]);
    Order::create(['signature' => 'ABC-1']);
    DB::enableQueryLog();

    OrderSearch::query('ABC')->within(Order::query())->get();
    $statements = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($statements)->toContain('SET LOCAL statement_timeout = 250');
});

it('keeps the phrase to bound parameters of the search statement', function (): void {
    Order::create(['signature' => 'ABCDEF']);
    DB::enableQueryLog();

    OrderSearch::query('ABCDEF')->within(Order::query())->get();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $search = array_values(array_filter($log, static fn (array $e): bool => str_contains($e['query'], 'DISTINCT ON')))[0];

    expect(stripos($search['query'], 'abcdef'))->toBeFalse()
        ->and(json_encode($search['bindings']))->toContain('abcdef');
});
