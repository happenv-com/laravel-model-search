<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Listeners;

use Happenv\ModelSearch\Exceptions\SchemaSyncIncomplete;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\DB;

/**
 * Installs the derived triggers and indexes after every `migrate`, so a new definition, a new
 * watched column and a fresh test database are all covered without a hand-written migration.
 *
 * A {@see SchemaSyncIncomplete} (a table stayed locked, an index build kept failing) propagates out
 * of the listener. The migrations themselves have already committed by then, so `migrate` exits
 * non-zero by design, with the schema migrated and part of the derived objects still missing: run
 * `model-search:sync` to finish. The same goes for a missing extension or a role that does not own
 * the tables.
 */
final readonly class SyncAfterMigrations
{
    public function __construct(private SchemaSync $sync) {}

    public function handle(MigrationsEnded $event): void
    {
        if ($event->method !== 'up' || ($event->options['pretend'] ?? false) === true) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql' || ! $this->sync->installed()) {
            return;
        }

        $this->sync->apply();
    }
}
