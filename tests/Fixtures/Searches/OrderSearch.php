<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Definition\SearchStage;
use Happenv\ModelSearch\Tests\Fixtures\Builders\OrderBuilder;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;

#[SearchesModel(Order::class)]
final class OrderSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('signature')->exactOnSource()->label(static fn (): string => 'Signature'),
            SearchField::key('external_number'),
            SearchField::key('customer_email'),
            SearchField::key('billingAddress.phone')->kind('phone'),
            SearchField::name('customer_name'),
            SearchField::name('shippingAddress.name')->kind('shipping_name'),
        ];
    }

    public function stages(): array
    {
        return [
            SearchStage::make('open', static fn (OrderBuilder $query): OrderBuilder => $query->inOpenDemand())
                ->watching(['state'])
                ->label(static fn (): string => 'Open orders'),
            SearchStage::all(),
        ];
    }

    public function sortAt(): ?string
    {
        return 'purchased_at';
    }
}
