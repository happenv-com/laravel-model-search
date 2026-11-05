<?php

declare(strict_types=1);

namespace Happenv\ModelSearch;

use Happenv\ModelSearch\Drivers\DriverManager;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Support\ServiceProvider;

final class ModelSearchServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/model-search.php', 'model-search');

        $this->app->singleton(ModelSearchRegistry::class);
        $this->app->singleton(DriverManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/model-search.php' => $this->app->configPath('model-search.php'),
        ], 'model-search-config');
    }
}
