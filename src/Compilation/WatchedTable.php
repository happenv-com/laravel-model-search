<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

/**
 * A table whose changes can change the index, with the columns that matter.
 *
 * `child`: the table holds `fkColumn`, a foreign key to its parent node of type `parentNodeType`;
 * a change marks that parent. `self`: a change marks the table's own row as a node of type
 * `parentNodeType` (its own type), keyed by `fkColumn`.
 *
 * A polymorphic child table also carries the discriminator (`morphColumn = morphValue`): only rows
 * that match it belong to the path, so a trigger must test the OLD and the NEW row against it.
 *
 * @internal
 */
final readonly class WatchedTable
{
    /**
     * @param  list<string>  $columns  Sorted, without duplicates.
     * @param  'child'|'self'  $shape
     * @param  string  $connection  Name of the connection the table lives on.
     * @param  string  $quotedTable  The table as it is written in SQL: prefixed and quoted.
     * @param  list<StepCondition>  $conditions  Only rows that satisfy all of them belong to the path (the OLD and the NEW row are both tested).
     */
    public function __construct(
        public string $table,
        public array $columns,
        public string $shape,
        public string $parentNodeType,
        public string $fkColumn,
        public ?string $morphColumn,
        public ?string $morphValue,
        public string $connection,
        public string $quotedTable,
        public array $conditions = [],
    ) {}

    public function identity(): string
    {
        return implode('|', [$this->connection, $this->table, $this->shape, $this->parentNodeType, $this->fkColumn, $this->morphColumn ?? '', $this->morphValue ?? '', json_encode(array_map(static fn (StepCondition $condition): array => $condition->toArray(), $this->conditions), JSON_THROW_ON_ERROR)]);
    }

    /**
     * @param  list<string>  $columns
     */
    public function withColumns(array $columns): self
    {
        $columns = array_values(array_unique($columns));
        sort($columns);

        return new self($this->table, $columns, $this->shape, $this->parentNodeType, $this->fkColumn, $this->morphColumn, $this->morphValue, $this->connection, $this->quotedTable, $this->conditions);
    }
}
