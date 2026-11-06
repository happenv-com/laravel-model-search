<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

/**
 * One join of a path: from the previous table's `fromColumn` to this table's `toColumn`.
 *
 * `child` means this table holds the foreign key (`toColumn`) that points at the previous node;
 * `self` means the previous table holds the foreign key (`fromColumn`) and this table is a node itself.
 *
 * @internal
 */
final readonly class Step
{
    /**
     * @param  'child'|'self'  $shape
     * @param  array{sql: string, bindings: list<mixed>}|null  $derived  The table seen through the listed global scopes.
     * @param  bool  $multiplies  Whether one root can reach the same node through several rows of this step (pivot and through steps).
     * @param  list<StepCondition>  $conditions  The restrictions the field puts on the rows of this step.
     */
    public function __construct(
        public string $quotedTable,
        public string $fromColumn,
        public string $toColumn,
        public string $shape,
        public ?string $morphColumn,
        public ?string $morphValue,
        public ?string $softDeleteColumn,
        public ?array $derived,
        public bool $multiplies = false,
        public array $conditions = [],
    ) {}

    /**
     * @param  list<StepCondition>  $conditions
     */
    public function withConditions(array $conditions): self
    {
        return new self($this->quotedTable, $this->fromColumn, $this->toColumn, $this->shape, $this->morphColumn, $this->morphValue, $this->softDeleteColumn, $this->derived, $this->multiplies, $conditions);
    }
}
