<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\DynamicOrder;

/**
 * Follows relations that exist only because a test registered them at runtime.
 */
#[SearchesModel(DynamicOrder::class)]
final class DynamicOrderSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('signature'),
            SearchField::key('dynamicChild.market_order_number')->kind('market_number'),
        ];
    }
}
