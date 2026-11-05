<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

/**
 * Which branches a type reads in round two.
 *
 * @internal
 */
enum RoundTwoPhase
{
    /** A type with stages: the branches of the first stage. */
    case StageZero;

    /**
     * A type with stages whose first stage came out empty after the scope: its first-stage branches and, in the SAME
     * statement, behind an uncorrelated `NOT EXISTS` over them, the branches of every other stage.
     */
    case StageZeroThenAll;

    /** A type with stages: the branches of every stage. */
    case AllStages;

    /** A type without stages, or a search that ignores them. */
    case Unstaged;

    /**
     * The stage variants of {@see CandidateBranches} the phase reads; null for a type without stages.
     *
     * @return list<int>|null
     */
    public function variants(): ?array
    {
        return match ($this) {
            self::StageZero, self::StageZeroThenAll => [CandidateBranches::STAGE_ZERO],
            self::AllStages => [CandidateBranches::ALL_STAGES],
            self::Unstaged => null,
        };
    }
}
