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

    /** @var array<string, array<string, string>> */
    private array $formatted = [];

    /**
     * The type name to cast a bound array to (`uuid`, `int8`, `bpchar`, `text`, …).
     */
    public function of(Model $model, string $table, string $column): string
    {
        $type = $this->raw($model, $table, $column);

        if (preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            throw new InvalidFieldPath(sprintf('The column "%s.%s" has the type "%s", which cannot be used as a search key.', $table, $column, $type));
        }

        return $type;
    }

    /**
     * The catalog type name of a column as it is (an array type starts with an underscore: `_text`).
     */
    public function raw(Model $model, string $table, string $column): string
    {
        $connection = $model->getConnection();
        $key = $connection->getName().'|'.$table;

        if (! isset($this->tables[$key])) {
            $types = [];
            $formatted = [];

            foreach ($connection->getSchemaBuilder()->getColumns($table) as $definition) {
                $types[(string) $definition['name']] = (string) $definition['type_name'];
                $formatted[(string) $definition['name']] = (string) $definition['type'];
            }

            if ($types === []) {
                throw new InvalidFieldPath(sprintf('The table "%s" on the connection "%s" does not exist or has no columns, so its keys cannot be typed.', $table, $connection->getName()));
            }

            $this->tables[$key] = $types;
            $this->formatted[$key] = $formatted;
        }

        $type = $this->tables[$key][$column] ?? null;

        if ($type === null) {
            throw new InvalidFieldPath(sprintf('The column "%s.%s" does not exist on the connection "%s".', $table, $column, $connection->getName()));
        }

        return $type;
    }

    /**
     * The type of a column as PostgreSQL writes it (`integer`, `character varying(20)`, `public.citext`), schema
     * qualified where the type lives outside the search path, so it can be used in a cast.
     */
    public function formatted(Model $model, string $table, string $column): string
    {
        $this->raw($model, $table, $column);

        return $this->formatted[$model->getConnection()->getName().'|'.$table][$column];
    }
}
