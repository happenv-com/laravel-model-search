<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\Parcel;

#[SearchesModel(Parcel::class)]
final class ParcelSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('reference'),
            SearchField::key('carrierUnits.tracking_number')->kind('tracking_number'),
            SearchField::via('order', kinds: ['signature', 'market_order_number']),
        ];
    }
}
