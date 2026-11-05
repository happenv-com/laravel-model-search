<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Normalization;

/**
 * Normalizes identifier-like values (numbers, codes, phones, e-mails) for key lookups.
 *
 * NFKC, combining marks removed, transliterated to ASCII, lower-cased, and every separator
 * (whitespace, `-`, `_`, `.`, `/`, `#`, `+`, `,`, `:`, `;`) dropped.
 */
final class KeyNormalizer extends FoldsText
{
    public function normalize(string $value): string
    {
        return (string) preg_replace('/[\s\-_.\/#+,:;]+/u', '', self::fold($value));
    }

    /**
     * Query-side only: the key form under a locale-specific transliteration.
     */
    public function transliterated(string $value, string $locale): string
    {
        return (string) preg_replace('/[\s\-_.\/#+,:;]+/u', '', self::fold($value, $locale));
    }

    public function version(): int
    {
        return 1;
    }
}
