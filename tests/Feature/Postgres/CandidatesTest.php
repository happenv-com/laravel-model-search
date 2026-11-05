<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Exceptions\SearchRateLimited;
use Happenv\ModelSearch\Exceptions\UnsupportedQuery;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Query\CandidateRows;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
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

it('reads the raw rows of the index: the only source table read is the exactOnSource branch, through the given scope', function (): void {
    Order::create(['signature' => 'ABC-1', 'state' => 'unshipped', 'customer_name' => 'Jan Kowalski']);
    Order::create(['signature' => 'ABC-2', 'state' => 'shipped']);
    msIndex();
    $recorder = msRecorder();

    $rows = cdRows('ABC');
    $statements = array_column(msIndexStatements($recorder), 'query');
    $sql = strtolower(implode("\n", $statements));

    expect(array_column($rows, 'kind'))->toBe(['signature', 'signature'])
        ->and(array_column($rows, 'mode'))->toBe([Mode::Prefix, Mode::Prefix])
        ->and(array_column($rows, 'value'))->toBe(['ABC-1', 'ABC-2'])
        ->and(array_column($rows, 'stage'))->toBe(['open', 'all'])
        ->and(array_column($rows, 'type'))->toBe(['order', 'order'])
        ->and($statements)->toHaveCount(1)
        // The signature is exactOnSource: its branch is the one place the statement names the orders table.
        ->and(substr_count($sql, 'from "orders"'))->toBeGreaterThan(0)
        ->and($sql)->toContain('source_variants');
});

it('names no source table when no field is exactOnSource', function (): void {
    msUse([SearchField::key('signature'), SearchField::name('customer_name')]);
    Order::create(['signature' => 'ABC-1']);
    msIndex();
    $recorder = msRecorder();

    $rows = ConfigurableOrderSearch::query('ABC')->withoutScope('test')->candidates();
    $sql = strtolower(implode("\n", array_column(msIndexStatements($recorder), 'query')));

    expect($rows)->toHaveCount(1)
        ->and($sql)->not->toContain('"orders"')
        ->and($sql)->not->toContain('join lateral');
});

it('keeps the phrase and every statement off the host connection', function (): void {
    Order::create(['signature' => 'SECRETPHRASE-1']);
    msIndex();
    $heard = [];
    DB::listen(static function ($query) use (&$heard): void {
        $heard[] = $query->sql.json_encode($query->bindings);
    });

    cdRows('SECRETPHRASE');

    expect(stripos(implode('', $heard), 'secretphrase'))->toBeFalse();
});

it('reports a name word under the first prefix name field', function (): void {
    Order::create(['signature' => 'S-1', 'customer_name' => 'Jan Kowalski']);
    msIndex();

    $rows = cdRows('kowal');

    expect(array_column($rows, 'kind'))->toBe(['customer_name'])
        ->and($rows[0]['value'])->toBe('Jan Kowalski')
        ->and($rows[0]['mode'])->toBe(Mode::Prefix);
});

it('returns no value for a field that has a reveal rule, there being no model to ask, on every path', function (string $path): void {
    config(['model-search.driver' => $path === 'database' ? 'database' : 'postgres']);
    msUse([
        SearchField::key('signature'),
        SearchField::key('customer_email')->revealUsing(static fn (): bool => true),
        SearchField::name('customer_name')->revealUsing(static fn (): bool => true),
    ]);
    Order::create(['signature' => 'ABC-0', 'customer_email' => 'abc@example.com', 'customer_name' => 'Abcdef Person']);
    msIndex();

    if ($path === 'postgres, index not ready') {
        DB::table(TableNames::forConnection()->state())->where('subject_type', 'order')->update(['ready_at' => null]);
        (new IndexState)->forget('order');
    }

    $rows = ConfigurableOrderSearch::query('abc')->withoutScope('test')->candidates()->keyBy('kind');

    expect($rows['signature']['value'])->toBe('ABC-0')
        ->and($rows['customer_email']['value'])->toBeNull()
        ->and($rows['customer_name']['value'])->toBeNull();
})->with(['postgres', 'database', 'postgres, index not ready']);

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
