<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Indexing;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * The failures table: records whose projection could not be computed even on their own.
 *
 * Only the exception CLASS is kept, never its message: the message may quote the record's data.
 */
final readonly class PoisonRecords
{
    /** The drain tries a failed record again after this many hours, up to {@see self::MAX_AUTOMATIC_ATTEMPTS} times. */
    public const int RETRY_AFTER_HOURS = 24;

    public const int MAX_AUTOMATIC_ATTEMPTS = 5;

    /** SQLSTATEs that say the database, not the record, was the problem: the batch must be retried, not the record blamed. */
    private const array TRANSIENT_STATES = ['40001', '40P01', '55P03', '57014', '57P01', '57P02', '57P03', '08000', '08001', '08003', '08004', '08006'];

    public function __construct(private TableNames $names) {}

    /**
     * Whether a failure is the database's (deadlock, timeout, lost connection) rather than the record's.
     */
    public static function isTransient(Throwable $failure): bool
    {
        if ($failure instanceof DeadlockException) {
            return true;
        }

        return $failure instanceof QueryException && in_array((string) $failure->getCode(), self::TRANSIENT_STATES, true);
    }

    public function record(Connection $db, string $subjectType, string $subjectId, Throwable $failure): void
    {
        $table = $this->table();

        $db->affectingStatement(
            sprintf('INSERT INTO %1$s ("subject_type", "subject_id", "attempts", "last_error_class", "last_failed_at") VALUES (?, ?, 1, ?, clock_timestamp()) ON CONFLICT ("subject_type", "subject_id") DO UPDATE SET "attempts" = %1$s."attempts" + 1, "last_error_class" = EXCLUDED."last_error_class", "last_failed_at" = EXCLUDED."last_failed_at"', $table),
            [$subjectType, $subjectId, $failure::class],
        );
    }

    /**
     * @param  list<string>  $subjectIds
     */
    public function clear(Connection $db, string $subjectType, array $subjectIds): void
    {
        if ($subjectIds === []) {
            return;
        }

        $db->affectingStatement(
            sprintf('DELETE FROM %s WHERE "subject_type" = ? AND "subject_id" = ANY(?::text[])', $this->table()),
            [$subjectType, Sql::arrayLiteral($subjectIds)],
        );
    }

    /**
     * Puts failed records back into the change log, for another try. Their attempt counter starts over.
     *
     * @param  list<string>|null  $subjectTypes  Null means every type.
     * @param  list<string>|null  $subjectIds  Null means every record of those types.
     */
    public function requeue(Connection $db, ?array $subjectTypes = null, ?array $subjectIds = null): int
    {
        $where = '';
        $bindings = [];

        if ($subjectTypes !== null) {
            $where .= ' AND "subject_type" = ANY(?::text[])';
            $bindings[] = Sql::arrayLiteral($subjectTypes);
        }

        if ($subjectIds !== null) {
            $where .= ' AND "subject_id" = ANY(?::text[])';
            $bindings[] = Sql::arrayLiteral($subjectIds);
        }

        return $db->transaction(function () use ($db, $where, $bindings): int {
            $queued = $db->affectingStatement(
                sprintf('INSERT INTO %s ("node_type", "node_id") SELECT "subject_type", "subject_id" FROM %s WHERE true%s', $this->names->quoted($this->names->changes()), $this->table(), $where),
                $bindings,
            );

            $db->affectingStatement(sprintf('UPDATE %s SET "attempts" = 0 WHERE true%s', $this->table(), $where), $bindings);

            return $queued;
        });
    }

    /**
     * Puts the records whose last failure is old enough (and that have not used up their attempts)
     * back into the change log, and restarts their clock so they are not queued again at once.
     *
     * @param  list<string>  $knownTypes  Only the root types this release knows (a failure of another type is not ours to retry).
     */
    public function requeueDue(Connection $db, array $knownTypes): int
    {
        if ($knownTypes === []) {
            return 0;
        }

        return $db->affectingStatement(
            sprintf(
                'WITH "due" AS (UPDATE %1$s SET "last_failed_at" = clock_timestamp() WHERE "subject_type" = ANY(?::text[]) AND "attempts" < %2$d AND "last_failed_at" < clock_timestamp() - interval \'%3$d hours\' RETURNING "subject_type", "subject_id") INSERT INTO %4$s ("node_type", "node_id") SELECT "subject_type", "subject_id" FROM "due"',
                $this->table(),
                self::MAX_AUTOMATIC_ATTEMPTS,
                self::RETRY_AFTER_HOURS,
                $this->names->quoted($this->names->changes()),
            ),
            [Sql::arrayLiteral($knownTypes)],
        );
    }

    private function table(): string
    {
        return $this->names->quoted($this->names->failures());
    }
}
