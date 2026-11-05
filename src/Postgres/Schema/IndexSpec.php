<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

/**
 * One planned index: its name (which carries a hash of the definition), its family (the stable role), the table it belongs to and the exact statement that creates it.
 *
 * The marker with the hash of the statement is stored as the index comment, so a changed definition (a new
 * predicate, a leading partition column) is detected as drift and the index is rebuilt.
 */
final readonly class IndexSpec
{
    public function __construct(
        public string $name,
        public string $family,
        public string $table,
        public string $createSql,
    ) {}

    public function comment(): string
    {
        return Marker::of($this->createSql);
    }
}
