<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

use Happenv\ModelSearch\Exceptions\InvalidFieldPath;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads the real PostgreSQL type of key columns from the catalog, once per compilation, so that
 * every comparison binds an array of exactly the column's type and stays index-usable.
 *
 * @internal
 */
final class ColumnTypes
{
    /** @var array<string, array<string, string>> */
    private array $tables = [];

    /**
     * The type name to cast a bound array to (`uuid`, `int8`, `bpchar`, `text`, …).
     */
    public function of(Model $model, string $table, string $column): string
    {
        $connection = $model->getConnection();
        $key = $connection->getName().'|'.$table;

        if (! isset($this->tables[$key])) {
            $types = [];

            foreach ($connection->getSchemaBuilder()->getColumns($table) as $definition) {
                $types[(string) $definition['name']] = (string) $definition['type_name'];
            }

            if ($types === []) {
                throw new InvalidFieldPath(sprintf('The table "%s" does not exist or has no columns, so its keys cannot be typed.', $table));
            }

            $this->tables[$key] = $types;
        }

        $type = $this->tables[$key][$column] ?? null;

        if ($type === null) {
            throw new InvalidFieldPath(sprintf('The column "%s.%s" does not exist.', $table, $column));
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            throw new InvalidFieldPath(sprintf('The column "%s.%s" has the type "%s", which cannot be used as a search key.', $table, $column, $type));
        }

        return $type;
    }
}
