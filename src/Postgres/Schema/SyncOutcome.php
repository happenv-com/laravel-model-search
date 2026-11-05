<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

/**
 * What {@see SchemaSync::apply()} did: the changes it applied, or why it did nothing.
 */
final readonly class SyncOutcome
{
    /**
     * @param  list<SchemaChange>  $applied
     */
    public function __construct(public array $applied, public ?string $skipped = null) {}
}
