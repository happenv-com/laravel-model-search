<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Postgres\Session\SearchConnectionFactory;
use Happenv\ModelSearch\Query\SearchResult;
use Happenv\ModelSearch\Query\SearchResults;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Session\RecordingSearchConnectionFactory;
use Illuminate\Support\Facades\DB;

if (! function_exists('msIndex')) {
    /**
     * Brings the index up to date with the rows the test created, installing the types that were not.
     */
    function msIndex(): void
    {
        $registry = app(ModelSearchRegistry::class);
        $state = new IndexState;

        foreach ($registry->all() as $definition) {
            if ($state->find($definition->morphAlias) === null) {
                $state->recordInstall($definition);
            }
        }

        (new SearchIndexer($registry, $state))->drainNow();
    }
}

if (! function_exists('msRecorder')) {
    function msRecorder(): RecordingSearchConnectionFactory
    {
        $factory = new RecordingSearchConnectionFactory;
        app()->instance(SearchConnectionFactory::class, $factory);

        return $factory;
    }
}

if (! function_exists('msIndexStatements')) {
    /**
     * The statements that read the index.
     *
     * @return list<array{query: string, bindings: array<mixed>, time: float|null}>
     */
    function msIndexStatements(RecordingSearchConnectionFactory $recorder): array
    {
        return array_values(array_filter($recorder->log(), static fn (array $entry): bool => str_contains($entry['query'], 'model_search_keys') || str_contains($entry['query'], 'model_search_entries')));
    }
}

if (! function_exists('msSignatures')) {
    /**
     * @return list<string>
     */
    function msSignatures(SearchResults $results): array
    {
        return array_map(static fn (SearchResult $result): string => $result->model->signature, iterator_to_array($results));
    }
}

if (! function_exists('msUse')) {
    /**
     * Replaces the order definition with one the test configures, starting from a clean state table.
     *
     * @param  list<SearchField>  $fields
     */
    function msUse(array $fields): void
    {
        ConfigurableOrderSearch::$fields = $fields;
        $registry = new ModelSearchRegistry;
        $registry->register(ConfigurableOrderSearch::class);
        app()->instance(ModelSearchRegistry::class, $registry);
        DB::table(TableNames::forConnection()->state())->delete();
    }
}
