<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\DynamicInvoice;

/**
 * Finds an invoice through a parent order reached by a relation registered at runtime.
 */
#[SearchesModel(DynamicInvoice::class)]
final class DynamicInvoiceSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('number'),
            SearchField::via('dynamicOrder', kinds: ['signature']),
        ];
    }
}
