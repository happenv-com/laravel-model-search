<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use Happenv\ModelSearch\Compilation\Sql;

/**
 * The trigger function and triggers of one table, rendered to SQL.
 */
final readonly class RenderedTriggers
{
    /**
     * @param  string  $functionName  Bare name of the function.
     * @param  string  $qualifiedFunction  Schema-qualified, quoted, without the argument list.
     * @param  string  $functionBody  Exactly the text stored in pg_proc.prosrc.
     * @param  array<string, string>  $triggers  CREATE statement per trigger name.
     * @param  list<string>  $unwanted  Names of triggers a previous shape may have left behind.
     */
    public function __construct(
        public string $table,
        public string $quotedTable,
        public string $functionName,
        public string $qualifiedFunction,
        public string $functionBody,
        public string $functionSql,
        public array $triggers,
        public array $unwanted,
    ) {}

    /**
     * The statements that bring the table to this state, in order. Idempotent.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        $statements = [$this->functionSql, sprintf('COMMENT ON FUNCTION %s() IS %s', $this->qualifiedFunction, TriggerSql::literal(Marker::of($this->functionSql)))];

        foreach ($this->unwanted as $name) {
            $statements[] = sprintf('DROP TRIGGER IF EXISTS %s ON %s', Sql::ident($name), $this->quotedTable);
        }

        foreach ($this->triggers as $name => $sql) {
            $statements[] = $sql;
            $statements[] = sprintf('COMMENT ON TRIGGER %s ON %s IS %s', Sql::ident($name), $this->quotedTable, TriggerSql::literal(Marker::of($sql)));
        }

        return $statements;
    }
}
