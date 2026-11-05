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

        $variants = [$neutral];

        if ($locale !== null && $locale !== '') {
            $variants[] = $normalizer->transliterated($q, $locale);
        }

        return array_slice(array_values(array_unique(array_filter($variants, static fn (string $v): bool => $v !== ''))), 0, self::MAX);
    }

    /**
     * The key form of each variant, unique and non-empty.
     *
     * @return list<string>
     */
    public static function keysFor(string $q, ?string $locale): array
    {
        $normalizer = new KeyNormalizer;
        $keys = [$normalizer->normalize($q)];

        if ($locale !== null && $locale !== '') {
            $keys[] = $normalizer->transliterated($q, $locale);
        }

        return array_slice(array_values(array_unique(array_filter($keys, static fn (string $k): bool => $k !== ''))), 0, self::MAX);
    }
}
