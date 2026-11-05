<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Events;

/**
 * Dispatched when the database server settings could write a search phrase to its logs: when the
 * settings are read afresh (at most once a minute) and when a search could not disable parameter
 * logging. `refused` says whether the search was refused (`privacy: refuse`) or ran anyway
 * (`privacy: warn`). The violations name settings, never a phrase.
 */
final readonly class SearchPrivacyUnsafe
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(public array $violations, public bool $refused) {}
}
