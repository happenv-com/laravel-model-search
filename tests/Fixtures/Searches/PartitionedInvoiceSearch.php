<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;

/**
 * Invoices partitioned by tenant, following orders that are not.
 */
#[SearchesModel(Invoice::class)]
final class PartitionedInvoiceSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('number'),
            SearchField::via('order', kinds: ['signature']),
        ];
    }

    public function partition(): ?string
    {
        return 'tenant_id';
    }
}
