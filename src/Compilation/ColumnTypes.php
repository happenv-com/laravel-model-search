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

    /**
     * The type a condition on a column is compared in: a domain is followed to its base type. `builtIn` tells whether
     * that type lives in pg_catalog (its operators are always found, whatever the search path, so it can be compared
     * typed); `comparable` whether it has a default btree equality, without which `=` does not exist for it (`json`,
     * `xml`, geometric types) and every write to the table would fail.
     *
     * @param  string  $quotedTable  The table as it is written in SQL.
     * @return array{name: string, formatted: string, builtIn: bool, comparable: bool}
     */
    public function resolved(Model $model, string $quotedTable, string $column): array
    {
        $row = $model->getConnection()->selectOne(
            <<<'SQL'
            WITH RECURSIVE "chain" AS (
                SELECT t."oid", t."typname", t."typtype", t."typcategory", t."typbasetype", t."typtypmod", t."typnamespace", a."atttypmod" AS "typmod"
                FROM "pg_catalog"."pg_attribute" a
                JOIN "pg_catalog"."pg_type" t ON t."oid" = a."atttypid"
                WHERE a."attrelid" = ?::regclass AND a."attname" = ?
                UNION ALL
                SELECT b."oid", b."typname", b."typtype", b."typcategory", b."typbasetype", b."typtypmod", b."typnamespace", c."typtypmod"
                FROM "chain" c
                JOIN "pg_catalog"."pg_type" b ON b."oid" = c."typbasetype"
                WHERE c."typtype" = 'd'
            )
            SELECT c."typname" AS "name", n."nspname" AS "schema", "pg_catalog"."format_type"(c."oid", c."typmod") AS "formatted",
                (
                    EXISTS (
                        SELECT 1 FROM "pg_catalog"."pg_opclass" oc JOIN "pg_catalog"."pg_am" am ON am."oid" = oc."opcmethod"
                        WHERE am."amname" = 'btree' AND oc."opcdefault" AND (
                            oc."opcintype" = c."oid"
                            OR (c."typtype" = 'e' AND oc."opcintype" = 'anyenum'::regtype)
                            OR (c."typcategory" = 'A' AND oc."opcintype" = 'anyarray'::regtype)
                            OR (c."typtype" = 'r' AND oc."opcintype" = 'anyrange'::regtype)
                        )
                    )
                    OR EXISTS (
                        SELECT 1 FROM "pg_catalog"."pg_cast" ca
                        JOIN "pg_catalog"."pg_opclass" oc ON oc."opcintype" = ca."casttarget"
                        JOIN "pg_catalog"."pg_am" am ON am."oid" = oc."opcmethod"
                        WHERE ca."castsource" = c."oid" AND ca."castmethod" = 'b' AND ca."castcontext" = 'i' AND am."amname" = 'btree' AND oc."opcdefault"
                    )
                ) AS "comparable"
            FROM "chain" c
            JOIN "pg_catalog"."pg_namespace" n ON n."oid" = c."typnamespace"
            WHERE c."typtype" <> 'd'
            SQL,
            [$quotedTable, $column],
        );

        if ($row === null) {
            throw new InvalidFieldPath(sprintf('The column "%s" of %s could not be resolved to a type.', $column, $quotedTable));
        }

        return [
            'name' => (string) $row->name,
            'formatted' => (string) $row->formatted,
            'builtIn' => $row->schema === 'pg_catalog',
            'comparable' => (bool) $row->comparable,
        ];
    }
}
