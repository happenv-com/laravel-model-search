<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Session;

use Happenv\ModelSearch\Postgres\Session\SearchConnectionFactory;
use Illuminate\Database\Connection;

/**
 * Keeps every search connection with its query log on, so a test can read what a search ran.
 */
final class RecordingSearchConnectionFactory extends SearchConnectionFactory
{
    /** @var list<Connection> */
    public array $connections = [];

    #[\Override]
    public function make(string $hostConnection): Connection
    {
        $connection = parent::make($hostConnection);
        $connection->enableQueryLog();

        return $this->connections[] = $connection;
    }

    /**
     * @return list<array{query: string, bindings: array<mixed>, time: float|null}>
     */
    public function log(): array
    {
        return array_values(array_merge(...array_map(static fn (Connection $connection): array => $connection->getQueryLog(), $this->connections)));
    }

    /**
     * What the search statements ran, without the settings reads and `SET LOCAL`s around them.
     *
     * @return list<array{query: string, bindings: array<mixed>, time: float|null}>
     */
    public function searches(): array
    {
        return array_values(array_filter($this->log(), static fn (array $entry): bool => str_contains($entry['query'], 'DISTINCT ON')));
    }
}
