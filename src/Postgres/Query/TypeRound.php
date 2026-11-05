<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

use Happenv\ModelSearch\Drivers\DatabaseDriver;
use Happenv\ModelSearch\Query\SearchResults;

/**
 * What round one found for one type: its best `limit` records, ranked, and the counts the driver
 * keeps to itself. They describe the pool the type was read from, which says something about rows
 * the viewer may not see, so they never reach {@see SearchResults}.
 *
 * @internal
 */
final readonly class TypeRound
{
    /**
     * @param  list<CandidateRow>  $rows  Ranked, at most `limit` of them.
     * @param  int  $passed  Records that passed authorization and the stage selection.
     * @param  int  $nonExactInStageZero  Authorized records of the first stage whose best hit is not an exact one.
     * @param  bool  $stageZeroFull  A branch of the first stage returned `pool + 1` rows.
     * @param  bool  $anyFull  A branch that counts for the first stage, or no stage at all, returned `pool + 1` rows.
     * @param  bool  $viaPoolFull  A `via` path found more than {@see DatabaseDriver::VIA_PARENT_LIMIT} parents in the parent's scope; the surplus gave no children.
     * @param  bool  $allStagesFull  A branch of all stages (of a type with stages, not exact) returned `pool + 1` rows.
     */
    public function __construct(
        public array $rows,
        public int $passed,
        public int $nonExactInStageZero,
        public bool $stageZeroFull,
        public bool $anyFull,
        public bool $allStagesFull,
        public bool $viaPoolFull,
        public int $limit,
    ) {}

    /**
     * What a later round found, completed with what an earlier one did: a record is kept once, in its
     * best (lowest) level, the later round first on ties; the best `limit` are kept, ordered by level.
     * The counts and flags are those of the later round.
     */
    public function mergedWith(self $earlier): self
    {
        $best = [];

        foreach ([...$this->rows, ...$earlier->rows] as $row) {
            if (! isset($best[$row->subjectId]) || $row->level < $best[$row->subjectId]->level) {
                $best[$row->subjectId] = $row;
            }
        }

        $rows = array_values($best);
        usort($rows, static fn (CandidateRow $a, CandidateRow $b): int => $a->level <=> $b->level);

        return new self(array_slice($rows, 0, $this->limit), max($this->passed, count($rows)), $this->nonExactInStageZero, $this->stageZeroFull, $this->anyFull, $this->allStagesFull, $this->viaPoolFull, $this->limit);
    }

    public static function empty(int $limit): self
    {
        return new self([], 0, 0, false, false, false, false, $limit);
    }

    /**
     * Fewer records than the limit passed authorization.
     */
    public function underfilled(): bool
    {
        return $this->passed < $this->limit;
    }

    /**
     * A branch of the first stage ran into the end of its pool.
     */
    public function stageZeroTruncated(): bool
    {
        return $this->stageZeroFull;
    }

    /**
     * A branch that decided the outcome ran into the end of its pool. A full branch of all stages
     * counts only when stage zero did not answer.
     */
    public function truncated(): bool
    {
        return $this->anyFull || ($this->allStagesFull && $this->nonExactInStageZero === 0 && ! $this->stageZeroFull);
    }

    /**
     * Whether the first stage answered with something besides exact hits.
     */
    public function answeredByStageZero(): bool
    {
        return $this->nonExactInStageZero > 0;
    }
}
