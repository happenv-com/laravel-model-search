<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Events;

/**
 * Dispatched after a batch that changed the projection of a root type has committed. Carries no
 * record, phrase or value.
 */
final readonly class SearchIndexChanged
{
    public function __construct(public string $subjectType) {}
}
