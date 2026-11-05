<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use Happenv\ModelSearch\Compilation\WatchedTable;

/**
 * Everything one watched table needs: the ways it is watched (a table can be the root of one
 * definition and a child of another) and the root types that read it, which a TRUNCATE rebuilds.
 *
 * @internal
 */
final readonly class TableTriggers
{
    /**
     * @param  string  $table  Bare table name, used in object names.
     * @param  string  $quotedTable  As written in SQL: prefixed and quoted.
     * @param  list<WatchedTable>  $entries
     * @param  list<string>  $roots  Morph aliases of every root type that reads the table.
     */
    public function __construct(
        public string $table,
        public string $quotedTable,
        public array $entries,
        public array $roots,
    ) {}
}
