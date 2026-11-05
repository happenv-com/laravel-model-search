<?php

declare(strict_types=1);

use Happenv\ModelSearch\Exceptions\UnsupportedHostQuery;
use Happenv\ModelSearch\Query\HostQuery;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Models\Parcel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

covers(HostQuery::class);

dataset('rejected host queries', [
    'limit' => [static fn (): Builder => Order::query()->limit(5)],
    'offset' => [static fn (): Builder => Order::query()->offset(5)],
    'order' => [static fn (): Builder => Order::query()->orderBy('signature')],
    'group' => [static fn (): Builder => Order::query()->groupBy('state')],
    'having' => [static fn (): Builder => Order::query()->groupBy('state')->having('state', '=', 'x')],
    'union' => [static fn (): Builder => Order::query()->union(DB::table('orders')->select('id'))],
    'custom select' => [static fn (): Builder => Order::query()->select('signature')],
    'select with a subquery' => [static fn (): Builder => Order::query()->withCount('parcels')],
    'aggregate' => [static fn (): Builder => Order::query()->selectRaw('count(*)')],
]);

it('rejects limit, order, group, having, union and custom select', function (Closure $make): void {
    expect(fn () => HostQuery::from($make()))->toThrow(UnsupportedHostQuery::class);
})->with('rejected host queries');

it('rejects a global scope that adds an ordering', function (): void {
    $builder = Order::query()->withGlobalScope('ordered', static fn (Builder $query) => $query->orderBy('signature'));

    expect(fn () => HostQuery::from($builder))->toThrow(UnsupportedHostQuery::class);
});

it('accepts from, joins, conditions and global scopes', function (): void {
    $builder = Parcel::query()
        ->join('orders', 'orders.id', '=', 'parcels.order_id')
        ->where('orders.state', 'unshipped')
        ->whereNotNull('parcels.reference');

    $query = HostQuery::from($builder)->sql();

    expect($query['sql'])->toContain('"parcels"."id" as "search_key"')
        ->and($query['sql'])->toContain('inner join "orders"')
        ->and($query['sql'])->toContain('"parcels"."deleted_at" is null')
        ->and($query['bindings'])->toBe(['unshipped']);
});

it('selects only the primary key', function (): void {
    Order::create(['signature' => 'A-1']);
    $query = HostQuery::from(Order::query()->where('signature', 'A-1'))->sql();

    $rows = DB::select($query['sql'], $query['bindings']);

    expect($rows)->toHaveCount(1)
        ->and(array_keys((array) $rows[0]))->toBe([HostQuery::KEY_ALIAS]);
});

it('hydrates in the given order with eager loads and afterQuery', function (): void {
    $a = Order::create(['signature' => 'A']);
    $b = Order::create(['signature' => 'B']);
    $c = Order::create(['signature' => 'C']);
    $seen = [];

    $host = HostQuery::from(
        Order::query()->with('parcels')->afterQuery(static function ($models) use (&$seen) {
            $seen[] = count($models);

            return $models;
        }),
    );

    $models = $host->hydrate([$c->id, $a->id, $b->id]);

    expect($models->pluck('signature')->all())->toBe(['C', 'A', 'B'])
        ->and($models->first()->relationLoaded('parcels'))->toBeTrue()
        ->and($seen)->toBe([3]);
});

it('hydrates only the given keys and keeps the scope', function (): void {
    $visible = Order::create(['signature' => 'V', 'state' => 'unshipped']);
    $hidden = Order::create(['signature' => 'H', 'state' => 'shipped']);

    $host = HostQuery::from(Order::query()->where('state', 'unshipped'));

    expect($host->hydrate([$hidden->id, $visible->id])->pluck('signature')->all())->toBe(['V'])
        ->and($host->hydrate([])->all())->toBe([]);
});

it('never mutates the host builder', function (): void {
    $builder = Order::query()->with('parcels')->where('state', 'unshipped');
    $sql = $builder->toSql();
    $bindings = $builder->getBindings();

    $host = HostQuery::from($builder);
    $host->sql();
    $host->hydrate(['00000000-0000-0000-0000-000000000000']);

    expect($builder->toSql())->toBe($sql)
        ->and($builder->getBindings())->toBe($bindings)
        ->and(array_keys($builder->getEagerLoads()))->toBe(['parcels']);
});

it('is not affected by later changes to the builder it was built from', function (): void {
    $builder = Order::query()->where('state', 'unshipped');
    $host = HostQuery::from($builder);

    $builder->where('signature', 'later');

    expect($host->sql()['sql'])->not->toContain('signature');
});

it('names the model of the host', function (): void {
    expect(HostQuery::from(Order::query())->model())->toBeInstanceOf(Order::class);
});
