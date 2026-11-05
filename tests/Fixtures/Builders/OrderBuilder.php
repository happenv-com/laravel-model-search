<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Builders;

use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Order>
 */
final class OrderBuilder extends Builder
{
    public function inOpenDemand(): self
    {
        return $this->whereIn('state', ['unshipped', 'partially_shipped']);
    }
}
