<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised after a sync that could not take a lock on some objects in time. Everything else was
 * installed; running the sync again finishes the rest.
 */
final class SchemaSyncIncomplete extends RuntimeException implements ModelSearchException
{
    /** @var list<InstallRefused> Installs that were refused in the same run. */
    public array $refusals = [];

    /**
     * @param  list<string>  $remaining  The objects that were left alone.
     * @param  list<InstallRefused>  $refusals
     */
    public static function leaving(array $remaining, array $refusals = []): self
    {
        $exception = new self(sprintf(
            'The sync could not take a lock in time and left %s alone. Everything else was installed; run model-search:sync again to finish.',
            implode(', ', $remaining),
        ));
        $exception->refusals = $refusals;

        return $exception;
    }
}
