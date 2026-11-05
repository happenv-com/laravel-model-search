<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Normalization;

/**
 * Classifies a search phrase before any query runs.
 */
final class PhraseShape
{
    /**
     * An identifier-like phrase contains `@`, or its alphanumeric characters are at least
     * `identifier_rule.digit_share` digits.
     */
    public static function isIdentifierLike(string $q): bool
    {
        if (str_contains($q, '@')) {
            return true;
        }

        $alphanumeric = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $q);
        $total = mb_strlen($alphanumeric);

        if ($total === 0) {
            return false;
        }

        $digits = preg_match_all('/\p{Nd}/u', $alphanumeric);

        return $digits / $total >= (float) config('model-search.identifier_rule.digit_share', 0.5);
    }

    /**
     * The words of the phrase after name normalization, split on whitespace and hyphens like the
     * indexed name words.
     *
     * @return list<string>
     */
    public static function words(string $q): array
    {
        $normalized = (new NameNormalizer)->normalize($q);

        return preg_split('/[\s\-]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
