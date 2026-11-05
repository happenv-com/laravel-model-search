<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Query\HostQuery;
use Happenv\ModelSearch\Query\SearchQuery;

/**
 * Round two of a palette: the types that round one could not settle, in ONE statement.
 *
 * Round one reads a pool of candidates in the order of the index and applies the viewer's scope
 * afterwards, so a pool eaten by records the viewer may not see (or by one record with many keys)
 * can leave a type empty or short while the index holds more. Round two reads the same branches
 * with the scope INSIDE each branch, as a lateral lookup by the primary key, so the scan stops
 * after the records in scope and the wait is bounded by the statement timeout, never by a pool.
 *
 * - A type with stages whose first stage came out empty after the scope although a branch of the
 *   first stage ran into the end of its pool: its stage-zero branches, scoped, in a CTE; the
 *   all-stages branches, scoped too, sit in the SAME statement behind an uncorrelated `NOT EXISTS`
 *   over that CTE, so they are read only when the first stage holds no non-exact hit. The type does
 *   NOT fall to "all stages" on the word of a pool that the scope may have emptied.
 * - A type that came out short ({@see TypeRound::underfilled()}) while a branch ran into the end
 *   of its pool: the same branches, scoped, for the stage that answered (the first stage when it
 *   did, all stages otherwise). A `via` path that found more parents than its cap of 50 does not
 *   start round two: the cap is the contract (P§8.2) and scoping the children cannot lift it.
 *
 * A branch is as long as the keys the best `limit` records can own ({@see self::KEYS_PER_RECORD}
 * per record): a record with several keys of one branch uses several rows of it. Deduplicating per
 * record inside a branch would need a sort and lose the early stop of the index order, so a record
 * owning more keys than that of one branch can still leave the type short. Seam (P5): a loose index
 * scan, if more than five keys per record and branch ever matters.
 *
 * What round two finds is MERGED with what round one found ({@see TypeRound::mergedWith()}).
 *
 * Round two never starts after a timeout of round one. Its flags are not read: nothing follows it.
 *
 * @internal
 */
final class RoundTwo
{
    /** The rows of a branch that one record of the result may use up. */
    public const int KEYS_PER_RECORD = 5;

    /**
     * Which types need round two, and what they read there.
     *
     * @param  array<string, array{definition: CompiledDefinition, query: SearchQuery, host: HostQuery}>  $targets
     * @param  array<string, TypeRound>  $rounds  What round one found, by morph alias.
     * @return array<string, RoundTwoPhase>
     */
    public static function phases(array $targets, array $rounds): array
    {
        $phases = [];

        foreach ($targets as $alias => $target) {
            $round = $rounds[$alias] ?? null;

            if (! $round instanceof TypeRound || ! $round->underfilled()) {
                continue;
            }

            $staged = CandidateBranches::isStaged($target['definition'], $target['query']);

            if ($staged && ! $round->answeredByStageZero() && $round->stageZeroTruncated()) {
                $phases[$alias] = RoundTwoPhase::StageZeroThenAll;
            } elseif ($round->truncated()) {
                $phases[$alias] = match (true) {
                    ! $staged => RoundTwoPhase::Unstaged,
                    $round->answeredByStageZero() => RoundTwoPhase::StageZero,
                    default => RoundTwoPhase::AllStages,
                };
            }
        }

        return $phases;
    }

    /**
     * The statement for the types in `$phases`; the type's position in it is its position among them.
     *
     * @param  array<string, array{definition: CompiledDefinition, query: SearchQuery, host: HostQuery}>  $targets
     * @param  array<string, RoundTwoPhase>  $phases
     * @return array{sql: string, bindings: list<mixed>}|null Null when no branch survives the phrase.
     */
    public static function sql(array $targets, array $phases): ?array
    {
        return RoundOne::sql(self::planned($targets, $phases));
    }

    /**
     * The targets of the phases, each with the plan of its phase, in the order of the phases.
     *
     * @param  array<string, array{definition: CompiledDefinition, query: SearchQuery, host: HostQuery}>  $targets
     * @param  array<string, RoundTwoPhase>  $phases
     * @return array<string, array{definition: CompiledDefinition, query: SearchQuery, host: HostQuery, plan: RoundPlan}>
     */
    public static function planned(array $targets, array $phases): array
    {
        $planned = [];

        foreach ($phases as $alias => $phase) {
            $target = $targets[$alias];
            $planned[$alias] = [...$target, 'plan' => RoundPlan::scoped($target['query']->maxResults(), $phase)];
        }

        return $planned;
    }
}
