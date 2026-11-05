<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Drivers;

use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Query\SearchResults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Executes a {@see SearchQuery}. The query has already refused to run unscoped.
 */
interface Driver
{
    /**
     * The best `limit` records, ranked, after the scope of the query.
     */
    public function get(SearchQuery $query): SearchResults;

    /**
     * Names that contain the words of the phrase; no typo tolerance unless the driver has the means.
     */
    public function similar(SearchQuery $query): SearchResults;

    /**
     * Narrows a table query to the records that match the phrase, all of them, unranked.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $tableQuery
     * @return Builder<TModel>
     *
     * @throws SearchTimedOut
     */
    public function apply(SearchQuery $query, Builder $tableQuery): Builder;

    /**
     * Raw candidates, with no scope applied and no model hydrated.
     *
     * @return Collection<int, array{type: string, id: string, mode: Mode, kind: string, value: string|null, stage: string|null}>
     */
    public function candidates(SearchQuery $query): Collection;
}
