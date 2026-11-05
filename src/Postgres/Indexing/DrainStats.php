<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Indexing;

/**
 * What one {@see SearchIndexer::drain()} call did. Counters only: no phrase, value or message ever
 * ends up here, so the object is safe to log and to hand to an event.
 */
final class DrainStats
{
    /** Batches of the change log that ran. */
    public int $logBatches = 0;

    /** Batches of a rebuild that ran. */
    public int $rebuildBatches = 0;

    /** Rows of the change log that were read (and, with them, removed). */
    public int $logRowsRead = 0;

    /** Unique nodes those rows named. */
    public int $nodes = 0;

    /** Nodes of a type no registered definition knows: skipped. */
    public int $unknownNodes = 0;

    /** Roots whose projection was recomputed. */
    public int $roots = 0;

    /** Roots handed back to the change log because a node reached too many of them for one batch. */
    public int $spilledRoots = 0;

    public int $entriesWritten = 0;

    public int $entriesDeleted = 0;

    public int $keysWritten = 0;

    public int $keysDeleted = 0;

    /** Key rows whose copy of the entry stage was rewritten because the root changed stage. */
    public int $keyStageRewrites = 0;

    /** Records that kept failing and went to the failures table. */
    public int $poisoned = 0;

    /** Log rows discarded because their root type was switched to a rebuild. */
    public int $logRowsDiscarded = 0;

    /** @var list<string> Root types switched from the change log to a rebuild. */
    public array $switchedTypes = [];

    /** @var list<string> Root types whose rebuild reached its end. */
    public array $rebuiltTypes = [];

    /** @var list<string> Root types whose projection changed. */
    public array $changedTypes = [];

    /** Whether another drainer held the lock when a batch wanted to start. */
    public bool $lockedOut = false;

    /** Age in seconds of the oldest change-log row when the drain started; null when the log was empty. */
    public ?int $lagSeconds = null;

    /**
     * Adds everything another stats object counted (one batch, one reindex attempt).
     */
    public function merge(self $other): void
    {
        $this->logBatches += $other->logBatches;
        $this->rebuildBatches += $other->rebuildBatches;
        $this->logRowsRead += $other->logRowsRead;
        $this->nodes += $other->nodes;
        $this->unknownNodes += $other->unknownNodes;
        $this->roots += $other->roots;
        $this->spilledRoots += $other->spilledRoots;
        $this->entriesWritten += $other->entriesWritten;
        $this->entriesDeleted += $other->entriesDeleted;
        $this->keysWritten += $other->keysWritten;
        $this->keysDeleted += $other->keysDeleted;
        $this->keyStageRewrites += $other->keyStageRewrites;
        $this->poisoned += $other->poisoned;
        $this->logRowsDiscarded += $other->logRowsDiscarded;
        $this->lockedOut = $this->lockedOut || $other->lockedOut;

        foreach ($other->switchedTypes as $type) {
            $this->switchedTypes[] = $type;
        }

        foreach ($other->rebuiltTypes as $type) {
            $this->rebuiltTypes[] = $type;
        }

        foreach ($other->changedTypes as $type) {
            $this->touch($type);
        }
    }

    public function touch(string $subjectType): void
    {
        if (! in_array($subjectType, $this->changedTypes, true)) {
            $this->changedTypes[] = $subjectType;
        }
    }

    public function wroteAnything(): bool
    {
        return $this->entriesWritten + $this->entriesDeleted + $this->keysWritten + $this->keysDeleted + $this->keyStageRewrites > 0;
    }
}
