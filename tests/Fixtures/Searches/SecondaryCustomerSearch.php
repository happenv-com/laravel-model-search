<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\SecondaryCustomer;

#[SearchesModel(SecondaryCustomer::class)]
final class SecondaryCustomerSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('email'),
            SearchField::name('name'),
        ];
    }
}
