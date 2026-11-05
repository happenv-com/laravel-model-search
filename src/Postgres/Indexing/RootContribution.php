<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Indexing;

/**
 * Everything the index holds for one root record: its entry and its key rows.
 *
 * @internal
 */
final readonly class RootContribution
{
    /**
     * @param  string  $names  Normalized names, joined by a space.
     * @param  string|null  $sortAt  A timestamptz in PostgreSQL's own text form.
     * @param  list<array{kind: string, key: string, value: string, stage: int}>  $keys  Every row carries the stage of the entry (MS-18).
     */
    public function __construct(
        public string $rootId,
        public int $stage,
        public string $names,
        public ?string $sortAt,
        public ?string $partition,
        public array $keys,
    ) {}
}
