<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Jobs;

use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs one drain (`dispatch(new DrainSearchIndex)`). The package does not schedule it; the host does, as often as its freshness
 * target needs. Overlapping runs are harmless: a batch takes an advisory lock and the loser stops.
 */
final class DrainSearchIndex implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly ?int $budgetMs = null) {}

    public function handle(SearchIndexer $indexer): void
    {
        $indexer->drain($this->budgetMs);
    }
}
