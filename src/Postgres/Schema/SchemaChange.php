<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

/**
 * One unit of work the sync would do: bring one table's triggers, or one index, to the planned state.
 *
 * @internal
 */
final readonly class SchemaChange
{
    /**
     * @param  'triggers'|'index'  $kind
     * @param  'create'|'replace'|'drop'  $action
     * @param  list<string>  $statements  Idempotent, in execution order.
     * @param  bool  $transactional  False for CREATE/DROP INDEX CONCURRENTLY, which cannot run in a transaction.
     * @param  list<string>  $relations  Tables (as SQL) whose owner must be the acting role.
     * @param  list<string>  $routines  Functions (as SQL, with arguments) whose owner must be the acting role.
     * @param  list<array{kind: 'function'|'trigger', name: string, on: string, sql: string}>  $stamps  Comments to write once the statements ran.
     * @param  string|null  $afterValid  For an index build: the statement (the marker comment) that may run only once the index named by `$subject` is valid.
     */
    public function __construct(
        public string $kind,
        public string $action,
        public string $subject,
        public string $reason,
        public array $statements,
        public bool $transactional,
        public array $relations = [],
        public array $routines = [],
        public array $stamps = [],
        public ?string $afterValid = null,
    ) {}

    public function describe(): string
    {
        return sprintf('%s %s %s (%s)', $this->action, $this->kind, $this->subject, $this->reason);
    }
}
