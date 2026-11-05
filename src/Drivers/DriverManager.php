<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Drivers;

use Closure;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use InvalidArgumentException;

/**
 * Resolves the driver that serves a definition, by the `model-search.driver` setting.
 */
final class DriverManager
{
    /** @var array<string, Closure(CompiledDefinition): Driver> */
    private array $custom = [];

    /**
     * @param  Closure(CompiledDefinition): Driver  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->custom[$name] = $factory;
    }

    public function for(CompiledDefinition $definition): Driver
    {
        $name = config('model-search.driver');
        $name = is_string($name) ? $name : '';

        if (isset($this->custom[$name])) {
            return ($this->custom[$name])($definition);
        }

        return match ($name) {
            'database' => new DatabaseDriver($definition),
            default => throw new InvalidArgumentException(sprintf('The search driver "%s" is not available.', $name)),
        };
    }
}
