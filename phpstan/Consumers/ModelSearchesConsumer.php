<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\PhpStan\Consumers;

use Happenv\ModelSearch\Integration\TrueModular\RegistersModelSearches;
use Illuminate\Support\ServiceProvider;

/**
 * Exists only so PHPStan analyses the {@see RegistersModelSearches} trait body, which it reaches
 * through a using class. Not autoloaded and not shipped.
 */
final class ModelSearchesConsumer extends ServiceProvider
{
    use RegistersModelSearches;
}
