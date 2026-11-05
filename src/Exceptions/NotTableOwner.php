<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised when the database role running the sync does not own a table it would have to change.
 */
final class NotTableOwner extends RuntimeException implements ModelSearchException {}
