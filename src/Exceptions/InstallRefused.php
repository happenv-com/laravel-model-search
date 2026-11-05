<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised when recording an install would let an older or foreign definition take over a root type.
 */
final class InstallRefused extends RuntimeException
{
    public static function downgrade(string $subjectType, int $stored, int $given): self
    {
        return new self(sprintf(
            'The root type "%s" is installed at definition version %d; version %d is older (probably the previous release after a migrate), so it was not installed.',
            $subjectType,
            $stored,
            $given,
        ));
    }

    public static function foreignFingerprint(string $subjectType, int $version): self
    {
        return new self(sprintf(
            'The root type "%s" is installed at definition version %d with a different fingerprint. Another release owns it; bump ModelSearch::version() to take it over.',
            $subjectType,
            $version,
        ));
    }
}
