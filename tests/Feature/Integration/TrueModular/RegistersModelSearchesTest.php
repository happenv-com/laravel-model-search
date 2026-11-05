<?php

declare(strict_types=1);

use Happenv\ModelSearch\Integration\TrueModular\RegistersModelSearches;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\MarketOrderSearchExtension;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Support\ServiceProvider;

covers(RegistersModelSearches::class);

it('registers through the true-modular trait', function (): void {
    $this->app->forgetInstance(ModelSearchRegistry::class);
    $this->app->singleton(ModelSearchRegistry::class);

    $module = new class($this->app) extends ServiceProvider
    {
        use RegistersModelSearches;
    };

    $returned = $module
        ->hasModelSearches([OrderSearch::class])
        ->hasModelSearchExtensions([MarketOrderSearchExtension::class])
        ->processModelSearches();

    $definition = $this->app->make(ModelSearchRegistry::class)->for(Order::class);

    expect($returned)->toBe($module)
        ->and($definition->fields)->toHaveCount(7)
        ->and($definition->fields[6]->kind)->toBe('market_order_number');
});
