<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Normalization;

use Illuminate\Support\Str;
use Normalizer as IntlNormalizer;

/**
 * Shared folding for the built-in normalizers: NFKC, combining marks removed, transliterated to
 * ASCII, lower-cased. Not public API.
 */
abstract class FoldsText implements Normalizer
{
    final protected static function fold(#[\SensitiveParameter] string $value, string $locale = 'en'): string
    {
        $composed = IntlNormalizer::normalize($value, IntlNormalizer::FORM_KC);
        $composed = is_string($composed) ? $composed : $value;
        $composed = (string) preg_replace('/\p{M}+/u', '', $composed);

        return mb_strtolower(Str::ascii($composed, $locale));
    }

    /**
     * NFKC and lower case only: the form in which a phrase is classified.
     */
    final protected static function foldUnicode(#[\SensitiveParameter] string $value): string
    {
        $composed = IntlNormalizer::normalize($value, IntlNormalizer::FORM_KC);

        return mb_strtolower(is_string($composed) ? $composed : $value);
    }
}
