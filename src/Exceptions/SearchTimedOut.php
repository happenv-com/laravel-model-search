<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised when a search that cannot report a partial result ran out of time. The message never carries the phrase.
 */
final class SearchTimedOut extends RuntimeException {}
