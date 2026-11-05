<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Console;

use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Puts records that could not be indexed back into the change log, once their cause is fixed.
 */
final class RetryFailuresCommand extends Command
{
    protected $signature = 'model-search:retry-failures
        {--subject=* : Morph alias or model class; every type when omitted}
        {--id=* : Record keys; every failed record of the chosen types when omitted}';

    protected $description = 'Re-queue records that failed to index so the next drain tries them again';

    public function handle(SearchIndexer $indexer, ModelSearchRegistry $registry): int
    {
        try {
            $types = SubjectOption::aliases($registry, (array) $this->option('subject'));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $ids = array_values(array_filter(array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', (array) $this->option('id')), static fn (string $id): bool => $id !== ''));

        $queued = $indexer->requeueFailures($types, $ids === [] ? null : $ids);

        $this->components->info(sprintf('%d record(s) re-queued.', $queued));

        return self::SUCCESS;
    }
}
