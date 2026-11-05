<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;

#[SearchesModel(Invoice::class)]
final class InvoiceSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('number'),
            SearchField::key('external_reference'),
            SearchField::via('order', kinds: ['signature']),
        ];
    }
}
