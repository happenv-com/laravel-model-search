<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Console;

use Happenv\ModelSearch\Exceptions\SchemaNotInstalled;
use Happenv\ModelSearch\Postgres\Schema\SchemaChange;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Illuminate\Console\Command;

/**
 * Brings the triggers and indexes derived from the registered definitions in step with the database.
 */
final class SyncCommand extends Command
{
    protected $signature = 'model-search:sync
        {--check : Report drift and exit with a failure when there is any; change nothing}
        {--dry-run : Print the DDL that would run; change nothing}';

    protected $description = 'Install and verify the change-capture triggers and the search indexes derived from the registered definitions';

    public function handle(SchemaSync $sync): int
    {
        try {
            if ($this->option('check') || $this->option('dry-run')) {
                return $this->report($sync);
            }

            $outcome = $sync->apply();
        } catch (SchemaNotInstalled $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($outcome->skipped !== null) {
            $this->components->warn(sprintf('Skipped: %s.', $outcome->skipped));

            return self::SUCCESS;
        }

        foreach ($outcome->applied as $change) {
            $this->components->twoColumnDetail($change->describe(), 'done');
        }

        $this->components->info($outcome->applied === [] ? 'Triggers and indexes are in sync.' : sprintf('%d change(s) applied.', count($outcome->applied)));

        return self::SUCCESS;
    }

    private function report(SchemaSync $sync): int
    {
        $changes = $sync->diff();

        if ($changes === []) {
            $this->components->info('Triggers and indexes are in sync.');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $this->components->warn($change->describe());

            if ($this->option('dry-run')) {
                $this->printStatements($change);
            }
        }

        if ($this->option('dry-run')) {
            $this->components->info(sprintf('%d change(s) pending; nothing was changed.', count($changes)));

            return self::SUCCESS;
        }

        $this->components->error(sprintf('Drift found in %d object(s); run model-search:sync.', count($changes)));

        return self::FAILURE;
    }

    private function printStatements(SchemaChange $change): void
    {
        foreach ($change->statements as $statement) {
            $this->line($statement.';');
        }
    }
}
