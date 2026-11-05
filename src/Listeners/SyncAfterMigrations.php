<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Listeners;

use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\DB;

/**
 * Installs the derived triggers and indexes after every `migrate`, so a new definition, a new
 * watched column and a fresh test database are all covered without a hand-written migration.
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
