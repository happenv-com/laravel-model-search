<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Enums;

enum OrderState: string
{
    case Unshipped = 'unshipped';
    case Shipped = 'shipped';
}
