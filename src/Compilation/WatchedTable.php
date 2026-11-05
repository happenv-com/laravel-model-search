<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

/**
 * A table whose changes can change the index, with the columns that matter.
 *
 * `child`: the table holds `fkColumn`, a foreign key to its parent node of type `parentNodeType`;
 * a change marks that parent. `self`: a change marks the table's own row as a node of type
 * `parentNodeType` (its own type), keyed by `fkColumn`.
 */
final readonly class WatchedTable
{
    /**
     * @param  list<string>  $columns  Sorted, without duplicates.
     * @param  'child'|'self'  $shape
     */
    public function __construct(
        public string $table,
        public array $columns,
        public string $shape,
        public string $parentNodeType,
        public string $fkColumn,
    ) {}

    public function identity(): string
    {
        return implode('|', [$this->table, $this->shape, $this->parentNodeType, $this->fkColumn]);
    }

    /**
     * @param  list<string>  $columns
     */
    public function withColumns(array $columns): self
    {
        $columns = array_values(array_unique($columns));
        sort($columns);

        return new self($this->table, $columns, $this->shape, $this->parentNodeType, $this->fkColumn);
    }
}
