<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Integration\TrueModular;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\ModelSearchExtension;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * For a module provider (laravel-true-modular is not required): declare the module's searches
 * fluently, and they are handed to the registry when the module initializes.
 *
 *     $this->hasModelSearches([OrderSearch::class])
 *         ->hasModelSearchExtensions([MarketOrderSearchExtension::class]);
 *
 * Call {@see self::processModelSearches()} from the provider's initialization hook.
 *
 * @phpstan-require-extends ServiceProvider
 */
trait RegistersModelSearches
{
    /** @var list<class-string<ModelSearch>> */
    public array $modelSearches = [];

    /** @var list<class-string<ModelSearchExtension>> */
    public array $modelSearchExtensions = [];

    /**
     * @param  list<class-string<ModelSearch>>  $definitions
     */
    public function hasModelSearches(array $definitions): static
    {
        foreach ($definitions as $definition) {
            $this->modelSearches[] = $definition;
        }

        return $this;
    }

    /**
     * @param  list<class-string<ModelSearchExtension>>  $extensions
     */
    public function hasModelSearchExtensions(array $extensions): static
    {
        foreach ($extensions as $extension) {
            $this->modelSearchExtensions[] = $extension;
        }

        return $this;
    }

    public function processModelSearches(): static
    {
        $registry = $this->app->make(ModelSearchRegistry::class);

        foreach ($this->modelSearches as $definition) {
            $registry->register($definition);
        }

        foreach ($this->modelSearchExtensions as $extension) {
            $registry->extend($extension);
        }

        return $this;
    }
}
