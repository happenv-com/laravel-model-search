<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;

#[SearchesModel(Order::class)]
final class HasManyViaOrderSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('signature'),
            SearchField::via('parcels'),
        ];
    }
}
