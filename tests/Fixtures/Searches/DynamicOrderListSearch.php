<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\DynamicOrder;

/**
 * Indexes a list column of a related record that a test registers at runtime.
 */
#[SearchesModel(DynamicOrder::class)]
final class DynamicOrderListSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('signature'),
            SearchField::key('codedItem.codes')->kind('item_code')->each(),
        ];
    }
}
