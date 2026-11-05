<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised when a PostgreSQL extension the package needs is not installed in the database.
 */
final class MissingExtension extends RuntimeException {}
