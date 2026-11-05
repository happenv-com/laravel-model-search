<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Console;

use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Illuminate\Console\Command;

/**
 * Brings the projection up to date with the change log and any pending rebuild.
 */
final class DrainCommand extends Command
{
    protected $signature = 'model-search:drain
        {--budget= : Time budget in milliseconds (defaults to drain.budget_ms)}';

    protected $description = 'Process the search change log and pending rebuilds';

    public function handle(SearchIndexer $indexer): int
    {
        $budget = $this->option('budget');
        $stats = $indexer->drain(is_numeric($budget) ? (int) $budget : null);

        if ($stats->lockedOut) {
            $this->components->warn('Another drainer holds the lock; nothing more to do here.');
        }

        $this->components->twoColumnDetail('Log rows read', (string) $stats->logRowsRead);
        $this->components->twoColumnDetail('Roots recomputed', (string) $stats->roots);
        $this->components->twoColumnDetail('Entries written / deleted', $stats->entriesWritten.' / '.$stats->entriesDeleted);
        $this->components->twoColumnDetail('Keys written / deleted', $stats->keysWritten.' / '.$stats->keysDeleted);

        if ($stats->poisoned > 0) {
            $this->components->warn(sprintf('%d record(s) could not be indexed; see the failures table.', $stats->poisoned));
        }

        if ($stats->unknownNodes > 0) {
            $this->components->warn(sprintf('%d node(s) of an unknown type were skipped.', $stats->unknownNodes));
        }

        return self::SUCCESS;
    }
}
