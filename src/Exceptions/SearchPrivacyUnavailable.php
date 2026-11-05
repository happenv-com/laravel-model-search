<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised when a search refuses to run because the server could write the phrase to its logs.
 *
 * The violations name server settings, never the phrase. The interface should say that search is
 * temporarily unavailable; `model-search:doctor` lists what to change.
 */
final class SearchPrivacyUnavailable extends RuntimeException implements ModelSearchException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Search is unavailable: the database server settings could write the search phrase to its logs.');
    }
}
