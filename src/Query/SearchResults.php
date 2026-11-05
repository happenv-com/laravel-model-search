<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Query;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The outcome of a palette-style search. `timedOut` means the search ran out of time: an empty
 * list is then not "nothing found". Nothing else about how complete the candidate pool was
 * leaves the driver, because it would describe rows the viewer may not see.
 *
 * @implements IteratorAggregate<int, SearchResult>
 */
final readonly class SearchResults implements Countable, IteratorAggregate
{
    /**
     * @param  list<SearchResult>  $items
     * @param  string|null  $stage  The stage key the results come from; null when stages did not apply.
     */
    public function __construct(
        public array $items = [],
        public bool $timedOut = false,
        public ?string $stage = null,
    ) {}

    /**
     * @return Traversable<int, SearchResult>
     */
    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    #[\Override]
    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
