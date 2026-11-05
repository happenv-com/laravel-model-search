<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Normalization;

/**
 * Turns a raw value into the form that is stored in, and compared against, the index.
 *
 * A normalizer may return an empty string (text with nothing left to index, such as CJK or
 * emoji, which ASCII folding drops, or invalid UTF-8): callers must drop empty values and never
 * index or query an empty key or word.
 *
 * Custom normalizers must bump {@see self::version()} whenever their behaviour changes: PHP
 * logic cannot be hashed, so the version is what lets the package notice the change.
 */
interface Normalizer
{
    public function normalize(#[\SensitiveParameter] string $value): string;

    public function version(): int;
}
