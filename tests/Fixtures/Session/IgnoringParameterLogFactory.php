<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Session;

use Happenv\ModelSearch\Postgres\Session\SearchConnectionFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;

/**
 * A search connection that silently drops the `SET LOCAL log_parameter_max_length_on_error`, as a
 * server that refuses the setting would: the read-back must catch it.
 */
final class IgnoringParameterLogFactory extends SearchConnectionFactory
{
    #[\Override]
    public function make(string $hostConnection): Connection
    {
        $host = DB::connection($hostConnection);

        return new class($host->getPdo(), $host->getDatabaseName(), $host->getTablePrefix(), $host->getConfig()) extends PostgresConnection
        {
            #[\Override]
            public function statement($query, $bindings = []): bool
            {
                return str_contains((string) $query, 'log_parameter_max_length_on_error') || parent::statement($query, $bindings);
            }
        };
    }
}
