<?php

declare(strict_types=1);

use Happenv\ModelSearch\Drivers\DatabaseDriver;
use Happenv\ModelSearch\Drivers\Driver;
use Happenv\ModelSearch\Drivers\DriverManager;
use Happenv\ModelSearch\Facades\ModelSearch;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;

covers(DriverManager::class);

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
