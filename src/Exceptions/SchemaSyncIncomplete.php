<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised after a sync that could not take a lock on some objects in time. Everything else was
 * installed; running the sync again finishes the rest.
 */
final class SchemaSyncIncomplete extends RuntimeException
{
    /**
     * @param  list<string>  $remaining  The objects that were left alone.
     */
    public static function leaving(array $remaining): self
    {
        return new self(sprintf(
            'The sync could not take a lock in time and left %s alone. Everything else was installed; run model-search:sync again to finish.',
            implode(', ', $remaining),
        ));
    }
}
