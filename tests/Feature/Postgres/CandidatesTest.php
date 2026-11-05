<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Exceptions\SearchRateLimited;
use Happenv\ModelSearch\Exceptions\UnsupportedQuery;
use Happenv\ModelSearch\Postgres\Query\CandidateRows;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;

covers(CandidateRows::class);

beforeEach(function (): void {
    config(['model-search.driver' => 'postgres']);
});

afterEach(fn () => ConfigurableOrderSearch::reset());

function cdRows(string $phrase): array
{
    return OrderSearch::query($phrase)->withoutScope('test')->candidates()->all();
}

it('reads the raw rows of the index with no scope, no host and no hydration', function (): void {
    Order::create(['signature' => 'ABC-1', 'state' => 'unshipped', 'customer_name' => 'Jan Kowalski']);
    Order::create(['signature' => 'ABC-2', 'state' => 'shipped']);
    msIndex();
    $recorder = msRecorder();
    $heard = [];
    DB::listen(static function ($query) use (&$heard): void {
        $heard[] = $query->sql;
    });

    $rows = cdRows('ABC');
    $sql = implode("\n", array_column(msIndexStatements($recorder), 'query'));

    expect(array_column($rows, 'kind'))->toBe(['signature', 'signature'])
        ->and(array_column($rows, 'mode'))->toBe([Mode::Prefix, Mode::Prefix])
        ->and(array_column($rows, 'value'))->toBe(['ABC-1', 'ABC-2'])
        ->and(array_column($rows, 'stage'))->toBe(['open', 'all'])
        ->and(array_column($rows, 'type'))->toBe(['order', 'order'])
        ->and(count(msIndexStatements($recorder)))->toBe(1)
        ->and($sql)->not->toContain('FROM "orders"')
        ->and(implode('', $heard))->not->toContain('orders');
});

it('reports a name word under the first prefix name field', function (): void {
    Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
    msIndex();

    $rows = cdRows('kowal');

    expect(array_column($rows, 'kind'))->toBe(['customer_name'])
        ->and($rows[0]['value'])->toBe('Jan Kowalski')
        ->and($rows[0]['mode'])->toBe(Mode::Prefix);
});

it('returns no value for a field that has a reveal rule, there being no model to ask', function (): void {
    msUse([
        SearchField::key('signature'),
        SearchField::key('customer_email')->revealUsing(static fn (): bool => true),
        SearchField::name('customer_name')->revealUsing(static fn (): bool => true),
    ]);
    Order::create(['signature' => 'S-1', 'customer_email' => 'abc@example.com', 'customer_name' => 'Abcdef Person']);
    msIndex();

    $rows = ConfigurableOrderSearch::query('abc')->withoutScope('test')->candidates()->keyBy('kind');

    expect($rows['customer_email']['value'])->toBeNull()
        ->and($rows['customer_name']['value'])->toBeNull();
});

it('stays inside the pool and orders the best mode first', function (): void {
    config(['model-search.pool' => 2]);
    Order::create(['signature' => 'ABC']);
    foreach (range(1, 5) as $i) {
        Order::create(['signature' => 'ABC-'.$i]);
    }
    msIndex();

    $rows = cdRows('ABC');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['mode'])->toBe(Mode::Exact);
});

it('charges the rate limit once and runs nothing for a phrase that normalizes to nothing', function (): void {
    Order::create(['signature' => 'ABC-1']);
    msIndex();
    config(['model-search.rate_limit' => ['max_attempts' => 1, 'decay_seconds' => 60]]);
    $viewer = new User;
    $viewer->forceFill(['id' => 41]);
    $query = static fn (string $phrase) => OrderSearch::query($phrase)->withoutScope('test')->forUser($viewer)->candidates();
    $recorder = msRecorder();

    expect($query('日本語')->all())->toBe([])
        ->and($recorder->log())->toBe([])
        ->and($query('ABC')->count())->toBe(1)
        ->and(fn () => $query('ABC'))->toThrow(SearchRateLimited::class);
});

it('refuses a strict subset of the name fields', function (): void {
    expect(fn () => OrderSearch::query('kowal')->withoutScope('test')->only(['customer_name'])->candidates())->toThrow(UnsupportedQuery::class);
});
