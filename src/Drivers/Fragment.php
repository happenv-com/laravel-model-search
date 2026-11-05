<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Drivers;

/**
 * A piece of SQL with its bindings, kept in the order the placeholders appear.
 *
 * @internal
 */
final readonly class Fragment
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {}

    /**
     * @param  array{sql: string, bindings: list<mixed>}  $compiled
     */
    public static function from(array $compiled): self
    {
        return new self($compiled['sql'], $compiled['bindings']);
    }

    /**
     * @param  list<Fragment>  $parts
     */
    public static function join(array $parts, string $glue): self
    {
        $bindings = [];

        foreach ($parts as $part) {
            array_push($bindings, ...$part->bindings);
        }

        return new self(implode($glue, array_map(static fn (self $part): string => $part->sql, $parts)), $bindings);
    }
}
