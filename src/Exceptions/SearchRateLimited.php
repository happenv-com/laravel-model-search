<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised before any query when a viewer searches more often than `model-search.rate_limit` allows.
 */
final class SearchRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(sprintf('Too many searches; try again in %d second(s).', $retryAfterSeconds));
    }
}
