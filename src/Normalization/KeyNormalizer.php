<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Normalization;

use Illuminate\Support\Str;
use Normalizer as IntlNormalizer;

/**
 * Normalizes identifier-like values (numbers, codes, phones, e-mails) for key lookups.
 *
 * NFKC, combining marks removed, transliterated to ASCII, lower-cased, and every separator
 * (whitespace, `-`, `_`, `.`, `/`, `#`, `+`, `,`, `:`, `;`) dropped.
 */
final class KeyNormalizer implements Normalizer
{
    public function normalize(string $value): string
    {
        return (string) preg_replace('/[\s\-_.\/#+,:;]+/u', '', self::fold($value));
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * Shared folding: NFKC, no combining marks, ASCII, lower case.
     */
    public static function fold(string $value, string $locale = 'en'): string
    {
        $composed = IntlNormalizer::normalize($value, IntlNormalizer::FORM_KC);
        $composed = is_string($composed) ? $composed : $value;
        $composed = (string) preg_replace('/\p{M}+/u', '', $composed);

        return mb_strtolower(Str::ascii($composed, $locale));
    }
}
