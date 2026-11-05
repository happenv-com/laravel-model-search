<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Drivers\DatabaseDriver;
use Happenv\ModelSearch\Drivers\Driver;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Session\SearchSession;
use Happenv\ModelSearch\Query\ChargeOnce;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Query\SearchResults;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Serves definitions from the projection (the keys and entries tables) the drain keeps.
 *
 * A palette is ONE statement for all the types of the call ({@see RoundOne}); `get()` is a palette of
 * one type. The statement runs on the search connection through {@see SearchSession}, which sets
 * the timeout and the privacy rules and keeps the phrase from the host's listeners; the models are
 * then loaded by the host's own query by primary key ({@see Hydrator}).
 *
 * A type whose index has never been built completely ({@see IndexState::isReady()}) is served by the
 * {@see DatabaseDriver} straight from its source tables, whatever its `fallback()` says: until then
 * there is no complete index to read, and the doctor says so. A palette that mixes ready and
 * unready types reads the ready ones in its one statement and the others one by one, and returns
 * them together, each type ranked on its own.
 *
 * Round two (a type that the first round could not settle), `similar()`, `apply()` and `candidates()`
 * are still served by the {@see DatabaseDriver}.
 *
 * The viewer's rate limit is charged once per palette, before its first statement.
 */
final readonly class PostgresDriver implements Driver
{
    public function __construct(private CompiledDefinition $definition) {}

    #[\Override]
    public function get(SearchQuery $query): SearchResults
    {
        return $this->palette([$query])[$query->definition()->morphAlias] ?? new SearchResults;
    }

    /**
     * Several types in one search.
     *
     * @param  list<SearchQuery>  $queries  One per model.
     * @return array<string, SearchResults> By morph alias, in the order of the queries.
     */
    public function palette(array $queries): array
    {
        $charge = new ChargeOnce;
        $queries = array_map(static fn (SearchQuery $query): SearchQuery => $query->sharingCharge($charge), $queries);
        $results = [];
        $ready = [];

        foreach ($queries as $query) {
            $definition = $query->definition();
            $results[$definition->morphAlias] = null;

            if ($this->isReady($definition)) {
                $ready[Sql::connectionName(new ($definition->modelClass))][] = $query;

                continue;
            }

            $results[$definition->morphAlias] = new DatabaseDriver($definition)->get($query);
        }

        foreach ($ready as $connection => $group) {
            $results = [...$results, ...$this->round($connection, $group)];
        }

        return array_filter($results, static fn (?SearchResults $outcome): bool => $outcome instanceof SearchResults);
    }

    #[\Override]
    public function similar(SearchQuery $query): SearchResults
    {
        return new DatabaseDriver($this->definition)->similar($query);
    }

    #[\Override]
    public function apply(SearchQuery $query, Builder $tableQuery): Builder
    {
        return new DatabaseDriver($this->definition)->apply($query, $tableQuery);
    }

    #[\Override]
    public function candidates(SearchQuery $query): Collection
    {
        return new DatabaseDriver($this->definition)->candidates($query);
    }

    /**
     * Round one for the queries on one connection.
     *
     * @param  list<SearchQuery>  $queries
     * @return array<string, SearchResults>
     */
    private function round(string $connection, array $queries): array
    {
        $targets = [];
        $limits = [];

        foreach ($queries as $query) {
            $definition = $query->definition();
            $targets[$definition->morphAlias] = ['definition' => $definition, 'query' => $query, 'host' => $query->scope()];
            $limits[$definition->morphAlias] = $query->maxResults();
        }

        $statement = RoundOne::sql($targets);

        if ($statement === null) {
            return array_map(static fn (array $target): SearchResults => new SearchResults, $targets);
        }

        $queries[0]->charge();

        try {
            $rows = resolve(SearchSession::class)->run(
                $connection,
                $this->timeout(),
                static fn (Connection $db): array => array_values($db->select($statement['sql'], $statement['bindings'])),
            );
        } catch (SearchTimedOut) {
            return array_map(static fn (array $target): SearchResults => new SearchResults(timedOut: true), $targets);
        }

        $rounds = RoundOne::read($rows, array_keys($targets), $limits);
        $results = [];

        foreach ($targets as $alias => $target) {
            $round = $rounds[$alias];
            $results[$alias] = new SearchResults(
                Hydrator::results($target['query'], $target['definition'], $round),
                false,
                Hydrator::settledStage($target['query'], $target['definition'], $round),
            );
        }

        return $results;
    }

    private function isReady(CompiledDefinition $definition): bool
    {
        return new IndexState(Sql::connectionName(new ($definition->modelClass)))->isReady($definition->morphAlias);
    }

    private function timeout(): int
    {
        $milliseconds = config('model-search.search.timeout_ms');

        return is_int($milliseconds) && $milliseconds > 0 ? $milliseconds : 300;
    }
}
