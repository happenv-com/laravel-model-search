<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;

/**
 * An invoice that reads its order's signature: the same order node as {@see OrderPathParcelSearch} reads.
 */
#[SearchesModel(Invoice::class)]
final class OrderPathInvoiceSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('number'),
            SearchField::key('order.signature')->kind('order_signature'),
        ];
    }
}
