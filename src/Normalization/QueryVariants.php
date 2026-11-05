<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Normalization;

/**
 * The forms of a search phrase that are looked up together.
 *
 * The first is the neutral normalization (the form stored in the index); a locale-specific
 * transliteration (`Müller` → `mueller` for `de`) is added when it differs.
 */
final class QueryVariants
{
    public const int MAX = 3;

    /**
     * @return list<string>
     */
    public static function for(string $q, ?string $locale): array
    {
        $normalizer = new NameNormalizer;
        $neutral = $normalizer->normalize($q);

        if ($neutral === '') {
            return [];
        }

        $variants = [$neutral];

        if ($locale !== null && $locale !== '') {
            $variants[] = $normalizer->normalize($q, $locale);
        }

        return array_slice(array_values(array_unique($variants)), 0, self::MAX);
    }
}
