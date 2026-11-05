<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Console;

use Happenv\ModelSearch\Exceptions\InstallRefused;
use Happenv\ModelSearch\Exceptions\SchemaNotInstalled;
use Happenv\ModelSearch\Exceptions\SchemaSyncIncomplete;
use Happenv\ModelSearch\Postgres\Schema\SchemaChange;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Illuminate\Console\Command;

/**
 * Brings the triggers and indexes derived from the registered definitions in step with the database.
 *
 * Run it on a direct database connection: it holds a session advisory lock for the whole run,
 * which a transaction-pooled connection cannot keep.
 */
final class SyncCommand extends Command
{
    protected $signature = 'model-search:sync
        {--check : Report drift and exit with a failure when there is any; change nothing}
        {--dry-run : Print the DDL that would run; change nothing}
        {--prune : Also drop what no registered definition needs any more (run it once the previous release is gone)}';

    protected $description = 'Install and verify the change-capture triggers and the search indexes derived from the registered definitions';

    public function handle(SchemaSync $sync): int
    {
        try {
            if ($this->option('check') || $this->option('dry-run')) {
                return $this->report($sync);
            }

            $outcome = $sync->apply((bool) $this->option('prune'));
        } catch (SchemaNotInstalled|SchemaSyncIncomplete $exception) {
            $this->components->error($exception->getMessage());

            if ($exception instanceof SchemaSyncIncomplete) {
                $this->printRefusals($exception->refusals);
            }

            return self::FAILURE;
        }

        if ($outcome->skipped !== null) {
            $this->components->warn(sprintf('Skipped: %s.', $outcome->skipped));

            return self::SUCCESS;
        }

        foreach ($outcome->applied as $change) {
            $this->components->twoColumnDetail($change->describe(), 'done');
        }

        foreach ($outcome->installs as $type => $result) {
            if ($result !== 'unchanged') {
                $this->components->twoColumnDetail('install '.$type, $result);
            }
        }

        $failed = $this->printRefusals($outcome->refusals);

        $this->components->info($outcome->applied === [] ? 'Triggers and indexes are in sync.' : sprintf('%d change(s) applied.', count($outcome->applied)));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A downgrade is an older release running the command (a blue/green deploy): it leaves the
     * type alone and is only a warning, as in the listener that runs after migrations.
     *
     * @param  list<InstallRefused>  $refusals
     * @return bool Whether any refusal was not a downgrade.
     */
    private function printRefusals(array $refusals): bool
    {
        $failed = false;

        foreach ($refusals as $refusal) {
            if ($refusal->isDowngrade) {
                $this->components->warn($refusal->getMessage());

                continue;
            }

            $this->components->error($refusal->getMessage());
            $failed = true;
        }

        return $failed;
    }

    private function report(SchemaSync $sync): int
    {
        $changes = $sync->diff();
        $obsolete = $sync->obsolete();
        $dryRun = (bool) $this->option('dry-run');
        $prune = (bool) $this->option('prune');

        foreach ($changes as $change) {
            $this->components->warn($change->describe());

            if ($dryRun) {
                $this->printStatements($change);
            }
        }

        foreach ($obsolete as $change) {
            $this->components->warn('prune pending: '.$change->describe());

            if ($dryRun && $prune) {
                $this->printStatements($change);
            }
        }

        if ($dryRun) {
            $this->components->info(sprintf('%d change(s) pending; nothing was changed.', count($changes) + ($prune ? count($obsolete) : 0)));

            return self::SUCCESS;
        }

        $uninstalled = $sync->uninstalled();

        foreach ($uninstalled as $type => $reason) {
            $this->components->warn(sprintf('%s: %s', $type, $reason));
        }

        if ($changes === [] && $uninstalled === []) {
            $this->components->info($obsolete === [] ? 'Triggers and indexes are in sync.' : 'Triggers and indexes are in sync; run with --prune to remove what is no longer planned.');

            return self::SUCCESS;
        }

        $this->components->error(sprintf('Drift found in %d object(s) and %d type(s); run model-search:sync.', count($changes), count($uninstalled)));

        return self::FAILURE;
    }

    private function printStatements(SchemaChange $change): void
    {
        foreach ($change->statements as $statement) {
            $this->line($statement.';');
        }
    }
}
