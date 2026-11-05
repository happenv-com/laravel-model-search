<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use Throwable;

/**
 * Implemented by every exception the package throws on purpose, so a host can catch them all.
 */
interface ModelSearchException extends Throwable {}
