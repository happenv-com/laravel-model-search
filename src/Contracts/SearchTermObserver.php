<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Contracts;

/**
 * Lets the host register every search phrase with its own scrubbing mechanism (an error reporter's
 * data scrubber, say). The package never logs a phrase itself; this is the only place it leaves.
 *
 * Bind your implementation in the container. It is called before every search, so keep it cheap.
 */
interface SearchTermObserver
{
    public function observe(#[\SensitiveParameter] string $term): void;
}
