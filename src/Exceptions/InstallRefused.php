<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use RuntimeException;

/**
 * Raised when recording an install would let an older or foreign definition take over a root type.
 */
final class InstallRefused extends RuntimeException implements ModelSearchException
{
    /**
     * The rule a changed definition has to follow, quoted wherever a mismatch is reported.
     */
    public const string VERSION_RULE = 'Every change to a definition (its fields, modes, stages, normalizers or relations) or to name_words.*, partition_column or sortAt() requires a higher ModelSearch::version(); otherwise the type cannot be installed.';

    /** True when the stored version is higher (an older release); false for a foreign fingerprint at the same version. */
    public bool $isDowngrade = false;

    public static function downgrade(string $subjectType, int $stored, int $given): self
    {
        $exception = new self(sprintf(
            'The root type "%s" is installed at definition version %d; version %d is older (probably the previous release after a migrate), so it was not installed.',
            $subjectType,
            $stored,
            $given,
        ));
        $exception->isDowngrade = true;

        return $exception;
    }

    public static function foreignFingerprint(string $subjectType, int $version): self
    {
        return new self(sprintf(
            'The root type "%s" is installed at definition version %d with a different fingerprint: the definition changed without a higher version, or another release owns the type. To take it over, bump ModelSearch::version(). %s',
            $subjectType,
            $version,
            self::VERSION_RULE,
        ));
    }
}
