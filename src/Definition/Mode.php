<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

enum Mode: string
{
    case Exact = 'exact';
    case Prefix = 'prefix';
    case Suffix = 'suffix';
    case Infix = 'infix';
    case Fuzzy = 'fuzzy';
    case FullText = 'fulltext';

    /**
     * Ranking position of a mode: the lower, the stronger the match.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Exact => 0,
            self::Prefix => 1,
            self::Suffix => 2,
            self::Infix => 3,
            self::Fuzzy => 4,
            self::FullText => 5,
        };
    }

    public static function fromRank(int $rank): self
    {
        foreach (self::cases() as $mode) {
            if ($mode->rank() === $rank) {
                return $mode;
            }
        }

        throw new \ValueError(sprintf('%d is not a mode rank.', $rank));
    }
}
