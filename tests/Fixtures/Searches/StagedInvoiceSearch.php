<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Definition\SearchStage;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;

/**
 * Invoices with a first stage (the ones that belong to an order) that follow orders.
 */
#[SearchesModel(Invoice::class)]
final class StagedInvoiceSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('number'),
            SearchField::via('order', kinds: ['signature']),
        ];
    }

    public function stages(): array
    {
        return [
            SearchStage::make('attached', static fn ($query) => $query->whereNotNull('order_id'))->watching(['order_id']),
            SearchStage::all(),
        ];
    }
}
