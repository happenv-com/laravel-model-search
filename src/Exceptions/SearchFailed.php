<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised when the database rejects a search statement. It deliberately has no previous exception and no SQL: both would carry the phrase.
 */
final class SearchFailed extends RuntimeException {}
