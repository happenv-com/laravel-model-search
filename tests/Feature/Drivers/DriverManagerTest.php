<?php

declare(strict_types=1);

use Happenv\ModelSearch\Drivers\DatabaseDriver;
use Happenv\ModelSearch\Drivers\Driver;
use Happenv\ModelSearch\Drivers\DriverManager;
use Happenv\ModelSearch\Facades\ModelSearch;
use Happenv\ModelSearch\Postgres\Query\PostgresDriver;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Models\Product;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ProductSearch;

covers(DriverManager::class);

it('resolves the postgres driver', function (): void {
    config(['model-search.driver' => 'postgres']);

    expect(resolve(DriverManager::class)->for(ModelSearch::for(Order::class)))->toBeInstanceOf(PostgresDriver::class);
});

it('resolves the database driver', function (): void {
    config(['model-search.driver' => 'database']);

    expect(resolve(DriverManager::class)->for(ModelSearch::for(Order::class)))->toBeInstanceOf(DatabaseDriver::class);
});

it('resolves a registered driver by name', function (): void {
    config(['model-search.driver' => 'custom']);
    $driver = Mockery::mock(Driver::class);
    resolve(DriverManager::class)->extend('custom', static fn () => $driver);

    expect(resolve(DriverManager::class)->for(ModelSearch::for(Order::class)))->toBe($driver);
});

it('refuses a driver it does not know', function (): void {
    config(['model-search.driver' => 'nonsense']);

    expect(fn () => resolve(DriverManager::class)->for(ModelSearch::for(Order::class)))->toThrow(InvalidArgumentException::class);
});

it('shares one manager', function (): void {
    expect(resolve(DriverManager::class))->toBe(resolve(DriverManager::class));
});

it('answers a palette one query at a time for a driver that does not read the index', function (): void {
    config(['model-search.driver' => 'database']);
    Order::create(['signature' => 'ABC-1']);
    Product::create(['sku' => 'ABC-1']);

    $palette = resolve(DriverManager::class)->palette([
        OrderSearch::query('ABC-1')->within(Order::query()),
        ProductSearch::query('ABC-1')->within(Product::query()),
    ]);

    expect(array_keys($palette))->toBe(['order', 'product'])
        ->and(count($palette['order']))->toBe(1)
        ->and(count($palette['product']))->toBe(1);
});
