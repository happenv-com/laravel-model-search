<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Enums;

enum MarketOrderKind: string
{
    case Order = 'order';
    case Invoice = 'invoice';
}
