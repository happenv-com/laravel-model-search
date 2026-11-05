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
            self::StageZero => [CandidateBranches::STAGE_ZERO],
            self::AllStages => [CandidateBranches::ALL_STAGES],
            self::Unstaged => null,
        };
    }
}
