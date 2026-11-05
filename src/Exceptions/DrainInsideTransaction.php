<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use LogicException;

/**
 * Raised when a drain is started inside an open transaction: its advisory lock and the events it
 * dispatches after each batch would leak into the caller's transaction.
 */
final class DrainInsideTransaction extends LogicException implements ModelSearchException {}
