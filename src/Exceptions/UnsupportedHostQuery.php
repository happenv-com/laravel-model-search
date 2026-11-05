<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use LogicException;

/**
 * Raised when a host query uses a part that cannot be embedded in a search statement, or belongs to another model.
 */
final class UnsupportedHostQuery extends LogicException implements ModelSearchException {}
