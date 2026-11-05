<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised by `apply()` and `candidates()` for a type whose index has not been built completely yet
 * and whose definition allows no `fallback()`: the answer would be a silently partial list. The
 * message never carries the phrase. Run `model-search:drain` (the index builds in the background),
 * or allow the fallback.
 */
final class SearchIndexNotReady extends RuntimeException implements ModelSearchException {}
