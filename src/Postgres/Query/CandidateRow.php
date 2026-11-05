<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Drivers\Hit;

/**
 * One record of a round: the way it matched best, and the other ways it matched.
 *
 * @internal
 */
final readonly class CandidateRow
{
    /**
     * @param  list<array{kind: string, level: int, value: string|null, via: string|null}>  $alternatives  The other routes, best first.
     */
    public function __construct(
        public string $subjectId,
        public string $kind,
        public int $level,
        public ?string $value,
        public ?string $via,
        public bool $inStage,
        public array $alternatives = [],
        public int $weight = 0,
        public float $similarity = 0.0,
        public ?float $sortAt = null,
    ) {}

    /**
     * Whether this row ranks before the other by the ranking of the palette: level, then the weight of
     * the field, the similarity, the newest sort moment (none last); the key is the last word.
     * Negative: before. Zero: the same place (the key aside).
     */
    public function compareRank(self $other): int
    {
        return [$this->level, $other->weight, $other->similarity] <=> [$other->level, $this->weight, $this->similarity]
            ?: $this->compareSortMoment($this->sortAt, $other->sortAt);
    }

    private function compareSortMoment(?float $a, ?float $b): int
    {
        return match (true) {
            $a === $b => 0,
            $a === null => 1,
            $b === null => -1,
            default => $b <=> $a,
        };
    }

    public function mode(): Mode
    {
        return Mode::fromRank($this->level % Hit::VIA_OFFSET);
    }
}
