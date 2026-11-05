<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

use function Orchestra\Testbench\load_migration_paths;

/**
 * For tests that need what a rolled-back transaction hides: CREATE INDEX CONCURRENTLY, DDL that is
 * not transactional, and objects visible from another session.
 */
abstract class TruncationTestCase extends BaseTestCase
{
    use DatabaseTruncation;

    /**
     * Only registers the fixture migrations: DatabaseTruncation runs `migrate:fresh` once per
     * process, and running them here as well would create the fixture tables twice.
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        load_migration_paths($this->app, __DIR__.'/Fixtures/Migrations');
    }
}
