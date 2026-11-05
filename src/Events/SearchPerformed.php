<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Events;

/**
 * Dispatched after a search (`get()`, `similar()` or `candidates()`) ran. It carries no phrase,
 * no value and no record: only which definition was searched and how it went.
 */
final readonly class SearchPerformed
{
    public function __construct(
        public string $definition,
        public int $durationMs,
        public int $resultCount,
        public bool $timedOut,
    ) {}
}
