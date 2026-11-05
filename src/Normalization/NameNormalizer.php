<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Normalization;

/**
 * Normalizes human names and phrases: the same folding as {@see KeyNormalizer}, but hyphens
 * survive as word boundaries. Apostrophes are removed, other punctuation (`_ . / # + , : ;`) is
 * a word boundary, and runs of whitespace collapse to a single space.
 *
 * @internal
 */
final class NameNormalizer extends FoldsText
{
    public function normalize(#[\SensitiveParameter] string $value): string
    {
        return $this->tidy(self::fold($value));
    }

    /**
     * Query-side only: the name form under a locale-specific transliteration.
     */
    public function transliterated(#[\SensitiveParameter] string $value, string $locale): string
    {
        return $this->tidy(self::fold($value, $locale));
    }

    /**
     * The NFKC, lower-cased form, before any transliteration or separator handling.
     */
    public function unicodeFolded(#[\SensitiveParameter] string $value): string
    {
        return self::foldUnicode($value);
    }

    public function version(): int
    {
        return 1;
    }

    private function tidy(string $folded): string
    {
        $withoutApostrophes = str_replace(["'", '’', '`'], '', $folded);
        $spaced = (string) preg_replace('/[_.\/#+,:;\s]+/u', ' ', $withoutApostrophes);

        return trim($spaced);
    }
}
