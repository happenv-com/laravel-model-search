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
 * Two steps are required: the fluent `has…` calls only collect the classes (call them while the
 * provider registers or configures), and {@see self::processModelSearches()} hands them to the
 * registry. Call it from the provider's initialization hook (`moduleInitialized()` in
 * laravel-true-modular), after the container is ready; without it nothing is registered.
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
