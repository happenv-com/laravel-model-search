<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\Parcel;

/**
 * A parcel that reads its order's signature: the order node fans out to the parcels of the order.
 */
#[SearchesModel(Parcel::class)]
final class OrderPathParcelSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('reference'),
            SearchField::key('order.signature')->kind('order_signature'),
        ];
    }
}
