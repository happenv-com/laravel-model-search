<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Normalization;

/**
 * Turns a raw value into the form that is stored in, and compared against, the index.
 *
 * Custom normalizers must bump {@see self::version()} whenever their behaviour changes: PHP
 * logic cannot be hashed, so the version is what lets the package notice the change.
 */
interface Normalizer
{
    public function normalize(string $value): string;

    public function version(): int;
}
