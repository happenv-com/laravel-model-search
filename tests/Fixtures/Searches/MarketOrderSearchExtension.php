<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearchExtension;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;

final class MarketOrderSearchExtension extends ModelSearchExtension
{
    public function model(): string
    {
        return Order::class;
    }

    public function fields(): array
    {
        return [
            SearchField::key('marketOrder.market_order_number')
                ->kind('market_order_number')
                ->label(static fn (): string => 'Market order number'),
        ];
    }
}
