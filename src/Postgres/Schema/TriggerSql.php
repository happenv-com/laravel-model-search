<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Compilation\WatchedTable;
use InvalidArgumentException;

/**
 * Generates the change-capture triggers of one watched table.
 *
 * One plpgsql function per table branches on TG_OP and, in each branch, touches only the record
 * that event has: NEW on INSERT, OLD and NEW on UPDATE, OLD on DELETE, and neither on TRUNCATE.
 * The function is SECURITY INVOKER with a fixed search_path, so every object it names is
 * schema-qualified. Columns are compared as text, never with the type's own `=`: that operator may
 * live in a schema outside the fixed search_path (an extension type such as hstore), and `json` has
 * none. Two values that print the same are the same for the purposes of the index.
 *
 * The role that writes to a watched table needs INSERT on the changes table, and, for a TRUNCATE
 * of a watched table, UPDATE on the state table, because the function runs with the privileges of
 * the invoker.
 *
 * The TRUNCATE branch updates the state rows of the root types that read the table. It is rare and
 * takes an ACCESS EXCLUSIVE lock on the truncated table already; should it ever meet a drain
 * updating the same state row, the deadlock detector resolves it and the truncate is retried by
 * the caller, so no extra locking order is imposed.
 *
 * Known limit: columns are compared through their text output, and the text of `float4`/`float8`
 * depends on the writing session's `extra_float_digits`. With a value of 0 or less, a change that
 * only shows in the digits that are not printed (a float8 moving from 0.1 to 0.1000000000000001) is
 * seen as no change and goes unlogged. Watch a float column only if such a change cannot matter, or
 * keep `extra_float_digits` at its default (1) or higher, which prints the shortest exact text.
 *
 * Two shapes of watching (see {@see WatchedTable}):
 * - child: the row holds a foreign key to its parent; the parent is logged (the old and the new
 *   one when the key moves). A polymorphic child is guarded on its discriminator for OLD and NEW.
 * - self: the row itself is the node, logged under its own node type.
 *
 * @internal
 */
final readonly class TriggerSql
{
    private const string TAG = '$ms$';

    public function __construct(private string $schema, private TableNames $names) {}

    public static function literal(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('A SQL literal cannot contain a NUL byte.');
        }

        $escaped = str_replace("'", "''", $value);

        return str_contains($value, '\\') ? "E'".str_replace('\\', '\\\\', $escaped)."'" : "'".$escaped."'";
    }

    public function render(TableTriggers $table): RenderedTriggers
    {
        $prefix = $this->names->prefix();
        $functionName = $this->objectName($prefix, $table->table, 'fn');
        $qualified = Sql::ident($this->schema).'.'.Sql::ident($functionName);

        $body = $this->body($table);

        if (str_contains($body, self::TAG)) {
            throw new InvalidArgumentException('A watched table, column or discriminator contains the dollar-quote tag the trigger function uses.');
        }

        $functionSql = sprintf(
            'CREATE OR REPLACE FUNCTION %s() RETURNS trigger LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog AS %s%s%s',
            $qualified,
            self::TAG,
            $body,
            self::TAG,
        );

        $execute = sprintf('EXECUTE FUNCTION %s()', $qualified);
        $triggers = [];
        $unwanted = [];

        $insert = $this->objectName($prefix, $table->table, 'ins');
        $triggers[$insert] = sprintf('CREATE OR REPLACE TRIGGER %s AFTER INSERT ON %s FOR EACH ROW %s', Sql::ident($insert), $table->quotedTable, $execute);

        $updateColumns = $this->updateColumns($table);
        $update = $this->objectName($prefix, $table->table, 'upd');

        if ($updateColumns === []) {
            $unwanted[] = $update;
        } else {
            $triggers[$update] = sprintf(
                'CREATE OR REPLACE TRIGGER %s AFTER UPDATE OF %s ON %s FOR EACH ROW WHEN (%s) %s',
                Sql::ident($update),
                implode(', ', array_map(Sql::ident(...), $updateColumns)),
                $table->quotedTable,
                implode(' OR ', array_map($this->distinct(...), $updateColumns)),
                $execute,
            );
        }

        $delete = $this->objectName($prefix, $table->table, 'del');
        $triggers[$delete] = sprintf('CREATE OR REPLACE TRIGGER %s AFTER DELETE ON %s FOR EACH ROW %s', Sql::ident($delete), $table->quotedTable, $execute);

        $truncate = $this->objectName($prefix, $table->table, 'trunc');
        $triggers[$truncate] = sprintf('CREATE OR REPLACE TRIGGER %s AFTER TRUNCATE ON %s FOR EACH STATEMENT %s', Sql::ident($truncate), $table->quotedTable, $execute);

        return new RenderedTriggers($table->table, $table->quotedTable, $functionName, $qualified, $body, $functionSql, $triggers, $unwanted);
    }

    /**
     * The names of the triggers a table can have, in every shape.
     *
     * @return list<string>
     */
    public function triggerNames(string $table): array
    {
        return array_map(fn (string $suffix): string => $this->objectName($this->names->prefix(), $table, $suffix), ['ins', 'upd', 'del', 'trunc']);
    }

    public function functionName(string $table): string
    {
        return $this->objectName($this->names->prefix(), $table, 'fn');
    }

    private function body(TableTriggers $table): string
    {
        $changes = Sql::ident($this->schema).'.'.Sql::ident($this->names->changes());
        $state = Sql::ident($this->schema).'.'.Sql::ident($this->names->state());

        $insert = [];
        $update = [];
        $delete = [];

        foreach ($table->entries as $entry) {
            array_push($insert, ...$this->log($changes, $entry, 'NEW'));
            array_push($update, ...$this->updateBlock($changes, $entry));
            array_push($delete, ...$this->log($changes, $entry, 'OLD'));
        }

        $roots = $table->roots;
        sort($roots);
        $truncate = $roots === []
            ? []
            : [sprintf(
                'UPDATE %s SET "rebuild_cursor" = %s, "rebuild_started_at" = clock_timestamp(), "sweep_cursor" = NULL WHERE "subject_type" IN (%s);',
                $state,
                self::literal(''),
                implode(', ', array_map(self::literal(...), $roots)),
            )];

        $branch = static fn (array $lines): string => implode("\n", array_map(static fn (string $line): string => '        '.$line, ['NULL;', ...$lines]));

        return implode("\n", [
            '',
            'BEGIN',
            "    IF TG_OP = 'INSERT' THEN",
            $branch($insert),
            "    ELSIF TG_OP = 'UPDATE' THEN",
            $branch($update),
            "    ELSIF TG_OP = 'DELETE' THEN",
            $branch($delete),
            "    ELSIF TG_OP = 'TRUNCATE' THEN",
            $branch($truncate),
            '    END IF;',
            '    RETURN NULL;',
            'END;',
            '',
        ]);
    }

    /**
     * Logs the node this entry names for the given record (NEW or OLD).
     *
     * @return list<string>
     */
    private function log(string $changes, WatchedTable $entry, string $record): array
    {
        return [sprintf('IF %s THEN %s END IF;', $this->matches($entry, $record), $this->insert($changes, $entry, $record))];
    }

    /**
     * On UPDATE: when a column that matters changed, log the old node and, unless it is the same
     * node, the new one. A no-op for this entry logs nothing.
     *
     * @return list<string>
     */
    private function updateBlock(string $changes, WatchedTable $entry): array
    {
        $changed = array_map($this->distinct(...), $this->entryColumns($entry));

        if ($changed === []) {
            return [];
        }

        $old = $this->matches($entry, 'OLD');
        $new = $this->matches($entry, 'NEW');
        $key = Sql::ident($entry->fkColumn);

        return [
            sprintf('IF %s THEN', implode(' OR ', $changed)),
            sprintf('    IF %s THEN %s END IF;', $old, $this->insert($changes, $entry, 'OLD')),
            sprintf('    IF %s AND NOT (%s AND OLD.%s::text IS NOT DISTINCT FROM NEW.%s::text) THEN %s END IF;', $new, $old, $key, $key, $this->insert($changes, $entry, 'NEW')),
            'END IF;',
        ];
    }

    private function insert(string $changes, WatchedTable $entry, string $record): string
    {
        return sprintf(
            'INSERT INTO %s ("node_type", "node_id") VALUES (%s, %s.%s::text);',
            $changes,
            self::literal($entry->parentNodeType),
            $record,
            Sql::ident($entry->fkColumn),
        );
    }

    /**
     * Whether the record belongs to the watched path: it has a key, and a polymorphic child has
     * the discriminator of the path.
     */
    private function matches(WatchedTable $entry, string $record): string
    {
        $condition = sprintf('%s.%s IS NOT NULL', $record, Sql::ident($entry->fkColumn));

        if ($entry->morphColumn !== null && $entry->morphValue !== null) {
            $condition .= sprintf(' AND %s.%s::text = %s', $record, Sql::ident($entry->morphColumn), self::literal($entry->morphValue));
        }

        return $condition;
    }

    /**
     * The columns whose change matters for one entry: its own columns, the key and the discriminator.
     *
     * @return list<string>
     */
    private function entryColumns(WatchedTable $entry): array
    {
        $columns = $entry->columns;

        if ($entry->shape === 'child') {
            $columns[] = $entry->fkColumn;

            if ($entry->morphColumn !== null) {
                $columns[] = $entry->morphColumn;
            }
        }

        $columns = array_values(array_unique($columns));
        sort($columns);

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function updateColumns(TableTriggers $table): array
    {
        $columns = [];

        foreach ($table->entries as $entry) {
            array_push($columns, ...$this->entryColumns($entry));
        }

        $columns = array_values(array_unique($columns));
        sort($columns);

        return $columns;
    }

    private function distinct(string $column): string
    {
        $quoted = Sql::ident($column);

        return sprintf('OLD.%s::text IS DISTINCT FROM NEW.%s::text', $quoted, $quoted);
    }

    /**
     * `<prefix><table>_<suffix>`, shortened with a hash of the table name when it would not fit
     * the 63 bytes PostgreSQL keeps of an identifier (it truncates silently, and two long names
     * would collide).
     */
    private function objectName(string $prefix, string $table, string $suffix): string
    {
        $name = $prefix.$table.'_'.$suffix;

        if (strlen($name) <= 63) {
            return $name;
        }

        $hash = substr(md5($table), 0, 10);
        $room = 63 - strlen($prefix) - strlen($hash) - strlen($suffix) - 2;

        return $prefix.substr($table, 0, max($room, 1)).'_'.$hash.'_'.$suffix;
    }
}
