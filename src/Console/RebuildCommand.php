<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Console;

use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Marks types for a rebuild; the drain does the work, interleaved with the live change log.
 */
final class RebuildCommand extends Command
{
    protected $signature = 'model-search:rebuild
        {--subject=* : Morph alias or model class; every installed type when omitted}';

    protected $description = 'Mark search types for a rebuild from their source tables (the drain performs it)';

    public function handle(SearchIndexer $indexer, ModelSearchRegistry $registry): int
    {
        try {
            $requested = SubjectOption::aliases($registry, (array) $this->option('subject'));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $marked = $indexer->rebuild($requested);
        $missing = array_values(array_diff($requested ?? [], $marked));

        foreach ($marked as $type) {
            $this->components->twoColumnDetail($type, 'rebuild pending');
        }

        foreach ($missing as $type) {
            $this->components->warn(sprintf('"%s" is not installed, so it was not marked; install it first.', $type));
        }

        $this->components->info(sprintf('%d type(s) marked; run model-search:drain (or let the scheduled drain) do the rebuild.', count($marked)));

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }
}
