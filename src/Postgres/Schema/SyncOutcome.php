<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use Happenv\ModelSearch\Exceptions\InstallRefused;

/**
 * What {@see SchemaSync::apply()} did: the changes it applied, or why it did nothing, and what
 * happened to the install of each registered type.
 *
 * @internal
 */
final readonly class SyncOutcome
{
    /**
     * @param  list<SchemaChange>  $applied
     * @param  array<string, string>  $installs  Per morph alias: installed, updated, unchanged, refused or skipped (its triggers or the indexes were not completed).
     * @param  list<InstallRefused>  $refusals
     */
    public function __construct(public array $applied, public ?string $skipped = null, public array $installs = [], public array $refusals = []) {}
}
