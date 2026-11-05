<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Session;

use Happenv\ModelSearch\Postgres\Session\SearchConnectionFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;

/**
 * A search connection whose read of `pg_settings` fails inside the database (an error that aborts
 * the transaction it runs in), as a role without access would.
 */
final class UnreadableSettingsFactory extends SearchConnectionFactory
{
    #[\Override]
    public function make(string $hostConnection): Connection
    {
        $host = DB::connection($hostConnection);

        return new class($host->getPdo(), $host->getDatabaseName(), $host->getTablePrefix(), $host->getConfig()) extends PostgresConnection
        {
            #[\Override]
            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
            {
                return parent::select(str_contains((string) $query, 'pg_settings') ? 'SELECT 1 / 0' : $query, str_contains((string) $query, 'pg_settings') ? [] : $bindings, $useReadPdo, $fetchUsing);
            }
        };
    }
}
