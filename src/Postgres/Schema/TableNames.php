<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use Happenv\ModelSearch\Compilation\Sql;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The names of the package's own tables: the configured prefix, optionally preceded by the
 * connection's table prefix.
 *
 * Raw SQL needs the connection prefix spelled out; the schema builder adds it itself, so a
 * migration builds this with an empty connection prefix.
 *
 * @internal
 */
final readonly class TableNames
{
    public function __construct(private string $prefix, private string $connectionPrefix = '')
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $prefix) !== 1) {
            throw new InvalidArgumentException(sprintf('The table prefix "%s" must be lower-case letters, digits and underscores.', $prefix));
        }
    }

    public static function forConnection(?string $connection = null): self
    {
        $prefix = config('model-search.table_prefix');

        return new self(is_string($prefix) ? $prefix : 'model_search_', DB::connection($connection)->getTablePrefix());
    }

    public function entries(): string
    {
        return $this->name('entries');
    }

    public function keys(): string
    {
        return $this->name('keys');
    }

    public function changes(): string
    {
        return $this->name('changes');
    }

    public function state(): string
    {
        return $this->name('state');
    }

    public function failures(): string
    {
        return $this->name('failures');
    }

    /**
     * The package prefix, which also starts the name of every trigger, function and index.
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Quoted, ready for SQL.
     */
    public function quoted(string $table): string
    {
        return Sql::ident($table);
    }

    private function name(string $suffix): string
    {
        return $this->connectionPrefix.$this->prefix.$suffix;
    }
}
