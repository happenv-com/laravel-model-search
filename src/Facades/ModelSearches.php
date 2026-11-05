<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Facades;

use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void register(string $definition)
 * @method static void extend(string $extension)
 * @method static CompiledDefinition for(string $modelClass)
 * @method static array<string, CompiledDefinition> all()
 * @method static bool isReady(string $modelClass)
 * @method static bool isRebuilding(string $modelClass)
 *
 * @see ModelSearchRegistry
 */
final class ModelSearches extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return ModelSearchRegistry::class;
    }
}
