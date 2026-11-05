<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Normalization;

/**
 * Normalizes human names and phrases: the same folding as {@see KeyNormalizer}, but word
 * separators survive and runs of whitespace collapse to a single space.
 */
final class NameNormalizer implements Normalizer
{
    public function normalize(string $value, string $locale = 'en'): string
    {
        $folded = KeyNormalizer::fold($value, $locale);

        return trim((string) preg_replace('/\s+/u', ' ', $folded));
    }

    public function version(): int
    {
        return 1;
    }
}
