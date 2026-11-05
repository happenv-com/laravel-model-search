<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\Product;

#[SearchesModel(Product::class)]
final class ProductSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('sku'),
            SearchField::key('ean'),
            SearchField::name('name')->translatable(),
        ];
    }
}
