<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Drivers;

use Closure;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Postgres\Query\PostgresDriver;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Query\SearchResults;
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
            'postgres' => new PostgresDriver($definition),
            default => throw new InvalidArgumentException(sprintf('The search driver "%s" is not available.', $name)),
        };
    }

    /**
     * Runs the queries of a palette: the ones served by the `postgres` driver together, in one
     * statement, every other driver's queries one by one.
     *
     * @param  list<SearchQuery>  $queries
     * @return array<string, SearchResults> By morph alias, in the order of the queries.
     */
    public function palette(array $queries): array
    {
        $results = [];
        $together = [];
        $postgres = null;

        foreach ($queries as $query) {
            $alias = $query->definition()->morphAlias;
            $driver = $this->for($query->definition());
            $results[$alias] = null;

            if ($driver instanceof PostgresDriver) {
                $postgres ??= $driver;
                $together[] = $query;

                continue;
            }

            $results[$alias] = $driver->get($query);
        }

        if ($postgres instanceof PostgresDriver) {
            $results = [...$results, ...$postgres->palette($together)];
        }

        return array_filter($results, static fn (?SearchResults $outcome): bool => $outcome instanceof SearchResults);
    }
}
