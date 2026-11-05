<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\CompiledField;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Drivers\DatabaseDriver;
use Happenv\ModelSearch\Drivers\Driver;
use Happenv\ModelSearch\Drivers\IdNarrowing;
use Happenv\ModelSearch\Exceptions\SearchIndexNotReady;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Session\SearchSession;
use Happenv\ModelSearch\Query\ChargeOnce;
use Happenv\ModelSearch\Query\HostQuery;
use Happenv\ModelSearch\Query\NameFieldSelection;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Query\SearchResults;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
 * {@see DatabaseDriver} straight from its source tables when its definition allows a `fallback()`;
 * otherwise `get()` searches only its `exactOnSource` fields meanwhile and never throws, while
 * `apply()` and `candidates()` throw {@see SearchIndexNotReady} (a silently partial list would
 * pass for the answer). In both cases every result of the call says `indexBuilding`, as it does
 * while a ready type is being rebuilt ({@see IndexState::isRebuilding()}; the existing index
 * serves the search, which may miss what the rebuild has yet to write). A palette that mixes ready and
 * unready types reads the ready ones in its one statement and the others one by one, and returns
 * them together, each type ranked on its own.
 *
 * A type the first round could not settle (a pool eaten by records the viewer may not see) goes on to
 * {@see RoundTwo}, which never starts after a timeout. `apply()` resolves the keys of the matching
 * records on the search connection ({@see ApplyIds}) and hands them to the table query; `similar()`
 * is one `Fuzzy` statement over the names ({@see SimilarQuery}). A type whose index is not ready is
 * answered from the source tables, or (without a fallback) by nothing for `similar()` and by the
 * refusal for `apply()` and `candidates()`.
 * `candidates()` reads the raw rows of round one from the index ({@see CandidateRows}).
 *
 * The viewer's rate limit is charged once per call, before its first statement.
 *
 * @internal
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
     * @param  ChargeOnce|null  $charge  The charge the whole palette shares, when the caller runs other drivers' queries too.
     * @return array<string, SearchResults> By morph alias, in the order of the queries.
     */
    public function palette(array $queries, ?ChargeOnce $charge = null): array
    {
        $charge ??= new ChargeOnce;
        $queries = array_map(static fn (SearchQuery $query): SearchQuery => $query->sharingCharge($charge), $queries);
        $results = [];
        $ready = [];
        $building = false;

        foreach ($queries as $query) {
            $definition = $query->definition();
            $results[$definition->morphAlias] = null;
            NameFieldSelection::assertWhole($definition, $query->onlyKinds());

            if ($this->isReady($definition)) {
                // A pending rebuild keeps the existing index in use, but the answer may miss what the rebuild is yet to write.
                $building = $building || $this->isRebuilding($definition);
                $ready[Sql::connectionName(new ($definition->modelClass))][] = $query;

                continue;
            }

            $building = true;
            $kinds = $this->sourceKinds($query, $definition);
            $results[$definition->morphAlias] = match (true) {
                $definition->fallback => new DatabaseDriver($definition)->get($query),
                $kinds === null => new SearchResults,
                default => new DatabaseDriver($definition)->get($query->only($kinds)->using(Mode::Exact)),
            };
        }

        foreach ($ready as $connection => $group) {
            $results = [...$results, ...$this->round($connection, $group)];
        }

        $results = array_filter($results, static fn (?SearchResults $outcome): bool => $outcome instanceof SearchResults);

        return $building ? array_map(static fn (SearchResults $outcome): SearchResults => $outcome->whileIndexBuilding(), $results) : $results;
    }

    /**
     * What a type without a fallback can answer while its index is being built: the `exactOnSource`
     * fields, which read the source table anyway (P§10.5), by their exact value, and nothing else.
     *
     * @return list<string>|null The kinds to search, null when there is nothing to search.
     */
    private function sourceKinds(SearchQuery $query, CompiledDefinition $definition): ?array
    {
        $modes = $query->onlyModes();
        $named = $query->onlyKinds();
        $kinds = array_values(array_map(
            static fn (CompiledField $field): string => $field->kind,
            array_filter($definition->fields, static fn (CompiledField $field): bool => $field->exactOnSource && ($named === [] || in_array($field->kind, $named, true))),
        ));

        if ($kinds === [] || ($modes !== [] && ! in_array(Mode::Exact, $modes, true))) {
            return null;
        }

        return $kinds;
    }

    #[\Override]
    public function similar(SearchQuery $query): SearchResults
    {
        NameFieldSelection::assertWhole($this->definition, $query->onlyKinds());

        if (! $this->isReady($this->definition)) {
            // The exactOnSource fields cannot answer a fuzzy search, so a type without a fallback has nothing to offer meanwhile.
            return ($this->definition->fallback ? new DatabaseDriver($this->definition)->similar($query) : new SearchResults)->whileIndexBuilding();
        }

        $statement = SimilarQuery::sql($this->definition, $query);

        if ($statement === null) {
            return new SearchResults;
        }

        $query->charge();

        try {
            $rows = resolve(SearchSession::class)->run(
                Sql::connectionName(new ($this->definition->modelClass)),
                $this->timeout('similar', 1000),
                static fn (Connection $db): array => array_values($db->select($statement['sql'], $statement['bindings'])),
            );
        } catch (SearchTimedOut) {
            return new SearchResults(timedOut: true);
        }

        $candidates = SimilarQuery::read($this->definition, $rows);
        $round = new TypeRound($candidates, count($candidates), 0, false, false, false, false, $query->maxResults());

        return new SearchResults(Hydrator::results($query, $this->definition, $round));
    }

    #[\Override]
    public function apply(SearchQuery $query, Builder $tableQuery): Builder
    {
        NameFieldSelection::assertWhole($this->definition, $query->onlyKinds());

        if (! $this->isReady($this->definition)) {
            if (! $this->definition->fallback) {
                throw $this->notReady('apply');
            }

            return new DatabaseDriver($this->definition)->apply($query, $tableQuery);
        }

        $statement = ApplyIds::sql($this->definition, $query);
        $ids = [];

        if ($statement !== null) {
            $query->charge();
            $rows = resolve(SearchSession::class)->run(
                Sql::connectionName(new ($this->definition->modelClass)),
                $this->timeout('search', 300),
                static fn (Connection $db): array => array_values($db->select($statement['sql'], $statement['bindings'])),
            );
            $ids = ApplyIds::read($rows);
        }

        $query->matched(count($ids));
        IdNarrowing::narrow($tableQuery, $ids, $this->definition->resolvedKeySqlType());

        return $tableQuery;
    }

    #[\Override]
    public function candidates(SearchQuery $query): Collection
    {
        NameFieldSelection::assertWhole($this->definition, $query->onlyKinds());

        if (! $this->isReady($this->definition)) {
            if (! $this->definition->fallback) {
                throw $this->notReady('candidates');
            }

            return new DatabaseDriver($this->definition)->candidates($query);
        }

        $statement = CandidateRows::sql($this->definition, $query);

        if ($statement === null) {
            return new Collection;
        }

        $query->charge();
        $rows = resolve(SearchSession::class)->run(
            Sql::connectionName(new ($this->definition->modelClass)),
            $this->timeout('search', 300),
            static fn (Connection $db): array => array_values($db->select($statement['sql'], $statement['bindings'])),
        );

        return CandidateRows::read($this->definition, $query, $rows);
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
                $this->timeout('search', 300),
                static fn (Connection $db): array => array_values($db->select($statement['sql'], $statement['bindings'])),
            );
        } catch (SearchTimedOut) {
            return array_map(static fn (array $target): SearchResults => new SearchResults(timedOut: true), $targets);
        }

        $rounds = RoundOne::read($rows, array_keys($targets), $limits);
        $timedOut = [];

        $this->completed($connection, $targets, $rounds, $timedOut);

        $results = [];

        foreach ($targets as $alias => $target) {
            $round = $rounds[$alias];
            $results[$alias] = new SearchResults(
                Hydrator::results($target['query'], $target['definition'], $round),
                isset($timedOut[$alias]),
                Hydrator::settledStage($target['query'], $target['definition'], $round),
            );
        }

        return $results;
    }

    /**
     * Round two for the types round one could not settle: ONE statement for all of them. What it
     * finds is merged with what round one found. A type whose statement ran out of time keeps what
     * round one found and is reported as timed out.
     *
     * @param  array<string, array{definition: CompiledDefinition, query: SearchQuery, host: HostQuery}>  $targets
     * @param  array<string, TypeRound>  $rounds  Replaced, type by type, by the merge with round two.
     * @param  array<string, true>  $timedOut
     */
    private function completed(string $connection, array $targets, array &$rounds, array &$timedOut): void
    {
        $pending = RoundTwo::phases($targets, $rounds);
        $statement = $pending === [] ? null : RoundTwo::sql($targets, $pending);

        if ($statement === null) {
            return;
        }

        try {
            $rows = resolve(SearchSession::class)->run(
                $connection,
                $this->timeout('search', 300),
                static fn (Connection $db): array => array_values($db->select($statement['sql'], $statement['bindings'])),
            );
        } catch (SearchTimedOut) {
            foreach (array_keys($pending) as $alias) {
                $timedOut[$alias] = true;
            }

            return;
        }

        $limits = [];

        foreach (array_keys($pending) as $alias) {
            $limits[$alias] = $targets[$alias]['query']->maxResults();
        }

        foreach (RoundOne::read($rows, array_keys($pending), $limits) as $alias => $found) {
            $rounds[$alias] = $found->mergedWith($rounds[$alias]);
        }
    }

    private function isReady(CompiledDefinition $definition): bool
    {
        return $this->state($definition)->isReady($definition->morphAlias);
    }

    private function isRebuilding(CompiledDefinition $definition): bool
    {
        return $this->state($definition)->isRebuilding($definition->morphAlias);
    }

    private function state(CompiledDefinition $definition): IndexState
    {
        return new IndexState(Sql::connectionName(new ($definition->modelClass)));
    }

    private function notReady(string $call): SearchIndexNotReady
    {
        return new SearchIndexNotReady(sprintf(
            'The index of %s is not built yet and its definition allows no fallback(), so %s() would return a partial list. Run model-search:drain until it is ready, or allow the fallback.',
            $this->definition->modelClass,
            $call,
        ));
    }

    /**
     * @param  'search'|'similar'  $setting
     */
    private function timeout(string $setting, int $default): int
    {
        $milliseconds = config('model-search.'.$setting.'.timeout_ms');

        return is_int($milliseconds) && $milliseconds > 0 ? $milliseconds : $default;
    }
}
