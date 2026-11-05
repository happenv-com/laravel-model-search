<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

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
     * The statements that bring the table to this state, in order. Idempotent, and creating or
     * replacing only: removing a trigger a table no longer needs is a prune step.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        return [$this->functionSql, ...array_values($this->triggers)];
    }

    /**
     * The comments to put on the objects once they exist: the marker needs the definition
     * PostgreSQL reads back, which is only known after the statements ran.
     *
     * @return list<array{kind: 'function'|'trigger', name: string, on: string, sql: string}>
     */
    public function stamps(): array
    {
        $stamps = [['kind' => 'function', 'name' => $this->qualifiedFunction, 'on' => $this->qualifiedFunction, 'sql' => $this->functionSql]];

        foreach ($this->triggers as $name => $sql) {
            $stamps[] = ['kind' => 'trigger', 'name' => $name, 'on' => $this->quotedTable, 'sql' => $sql];
        }

        return $stamps;
    }
}
