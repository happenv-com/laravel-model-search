<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Session;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Makes the connection a search runs on: a clone of the host's connection that tells nobody what
 * it runs.
 *
 * The clone shares the host's database session (so a search sees the host's open transaction and
 * a pooler pins nothing new) but has no event dispatcher, no query log and no transaction
 * manager, so the statements of a search, with the phrase among their bindings, never reach
 * `QueryExecuted` listeners, `DB::getQueryLog()` or a debugging tool. {@see SearchSession} also
 * makes the session send the phrase as a parameter while the search runs.
 */
class SearchConnectionFactory
{
    public function make(string $hostConnection): Connection
    {
        $host = DB::connection($hostConnection);

        // Resolve a lazy PDO first: a clone that resolved its own copy would open a second session.
        $host->getPdo();

        $clone = clone $host;
        $clone->unsetEventDispatcher();
        $clone->unsetTransactionManager();
        $clone->disableQueryLog();
        $clone->flushQueryLog();

        return $clone;
    }
}
