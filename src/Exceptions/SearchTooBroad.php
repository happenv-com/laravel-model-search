<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised when a phrase matches more records than `apply.max_ids` allows a table to be narrowed to.
 * The message never carries the phrase; the interface should ask for a longer one.
 */
final class SearchTooBroad extends RuntimeException {}
