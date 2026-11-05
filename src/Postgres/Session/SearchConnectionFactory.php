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
 * `QueryExecuted` listeners, `DB::getQueryLog()` or a debugging tool. The `beforeExecuting`
 * callbacks of the host are dropped for the same reason. {@see SearchSession} also makes the
 * session send the phrase as a parameter while the search runs.
 *
 * Why the session is shared and not opened anew (ruling R16): a second session per search would
 * multiply connections by tenants and workers, and it could not see the rows of the host's open
 * transaction (a test harness, a request that writes and searches). The price is that a search
 * always runs on the host's write PDO, so under read/write splitting searches go to the primary;
 * the clone reads from it too, so the privacy gate inspects the very server the search runs on.
 *
 * @internal
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
        $clone->useWriteConnectionWhenReading();

        // The callbacks receive the statement with its bindings; the host has no business seeing them.
        \Closure::bind(static function (Connection $connection): void {
            $connection->beforeExecutingCallbacks = [];
        }, null, Connection::class)($clone);

        return $clone;
    }
}
