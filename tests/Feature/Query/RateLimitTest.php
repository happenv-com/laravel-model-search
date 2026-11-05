<?php

declare(strict_types=1);

use Happenv\ModelSearch\Exceptions\SearchRateLimited;
use Happenv\ModelSearch\RateLimiting\SearchRateLimit;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

covers(SearchRateLimit::class);

beforeEach(function (): void {
    config(['model-search.driver' => 'database', 'model-search.rate_limit' => ['max_attempts' => 2, 'decay_seconds' => 60]]);
});

function rlSearch(int $user): void
{
    OrderSearch::query('ABC')->forUser(new GenericUser(['id' => $user]))->within(Order::query())->get();
}

it('rate limit throws before any query', function (): void {
    rlSearch(1);
    rlSearch(1);
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $caught = null;

    try {
        rlSearch(1);
    } catch (SearchRateLimited $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(SearchRateLimited::class)
        ->and($caught->retryAfterSeconds)->toBeGreaterThan(0)
        ->and($caught->getMessage())->not->toContain('ABC')
        ->and($queries)->toBe([]);
});

it('keeps one bucket per viewer', function (): void {
    rlSearch(1);
    rlSearch(1);

    expect(static fn () => rlSearch(2))->not->toThrow(SearchRateLimited::class);
});

it('limits every search method', function (): void {
    $user = new GenericUser(['id' => 9]);
    $query = OrderSearch::query('ABC')->forUser($user)->within(Order::query());
    $query->get();
    $query->similar();

    expect(static fn () => $query->apply(Order::query()))->toThrow(SearchRateLimited::class)
        ->and(static fn () => OrderSearch::query('ABC')->forUser($user)->withoutScope('test')->candidates())->toThrow(SearchRateLimited::class);
});

it('does not limit a search that has no viewer', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        OrderSearch::query('ABC')->forUser(null)->within(Order::query())->get();
    }

    expect(true)->toBeTrue();
});

it('can be switched off', function (): void {
    foreach ([false, null, ['max_attempts' => 0, 'decay_seconds' => 10]] as $off) {
        config(['model-search.rate_limit' => $off]);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            rlSearch(5);
        }
    }

    expect(true)->toBeTrue();
});

it('reads the pair form of the setting', function (): void {
    config(['model-search.rate_limit' => [1, 30]]);
    rlSearch(6);

    expect(static fn () => rlSearch(6))->toThrow(SearchRateLimited::class);
});
