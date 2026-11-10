<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Console;

use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Illuminate\Console\Command;

/**
 * Removes the trigger functions the package installed, and with them the triggers on the watched tables.
 *
 * For the `down()` of an application's own copy of the migration: a trigger must not outlive the change log it writes
 * to, or every later write to a watched table fails. Run it before dropping the package tables. The tables themselves
 * stay (they belong to the migration), and the next `migrate` or `model-search:sync` installs everything again.
 */
final class UninstallCommand extends Command
{
    protected $signature = 'model-search:uninstall';

    protected $description = 'Remove the triggers and trigger functions of the search index (before dropping its tables)';

    public function handle(SchemaSync $sync): int
    {
        $sync->uninstall();
        $this->components->info('The search index triggers are removed.');

        return self::SUCCESS;
    }
}
