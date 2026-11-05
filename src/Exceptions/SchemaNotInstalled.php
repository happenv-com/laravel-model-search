<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised when the package's own tables do not exist yet, so there is nothing to attach triggers and indexes to.
 */
final class SchemaNotInstalled extends RuntimeException {}
