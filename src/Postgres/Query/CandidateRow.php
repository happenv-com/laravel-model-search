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
    ) {}

    public function mode(): Mode
    {
        return Mode::fromRank($this->level % Hit::VIA_OFFSET);
    }
}
