<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

/**
 * How the statement reads one type: the size of the pool of each branch (a branch returns
 * `pool + 1` rows), whether the scope of the host is applied inside the branch, which stage
 * variants it reads, and whether the stage selection of round one applies.
 *
 * @internal
 */
final readonly class RoundPlan
{
    /**
     * @param  list<int>|null  $variants
     */
    public function __construct(
        public int $pool,
        public bool $scoped = false,
        public ?array $variants = null,
        public bool $filtersStage = true,
    ) {}

    /**
     * Round one: a pool of the configured size, the scope applied after it.
     */
    public static function first(): self
    {
        $pool = config('model-search.pool');

        return new self(is_int($pool) && $pool > 0 ? $pool : 200);
    }

    /**
     * Round two: the scope inside each branch, a branch as long as the keys the best `limit` records can own.
     */
    public static function scoped(int $limit, RoundTwoPhase $phase): self
    {
        return new self($limit * RoundTwo::KEYS_PER_RECORD - 1, true, $phase->variants(), false);
    }
}
