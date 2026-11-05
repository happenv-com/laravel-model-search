<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Normalization;

/**
 * Splits a normalized name into the words that are indexed for prefix search.
 *
 * Applies the minimum length, uniqueness and order of appearance. The per-record cap
 * (`name_words.max_per_record`) is applied later, across all of a record's names.
 */
final class NameWords
{
    /**
     * @return list<string>
     */
    public static function of(string $normalizedName): array
    {
        $minLength = (int) config('model-search.name_words.min_length', 2);
        $words = [];

        foreach (preg_split('/[\s\-]+/u', $normalizedName, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) >= $minLength) {
                $words[$word] = true;
            }
        }

        return array_map(strval(...), array_keys($words));
    }
}
