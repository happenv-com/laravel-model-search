<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Indexing;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Postgres\Schema\SubjectKey;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Illuminate\Database\Connection;

/**
 * Writes the DIFFERENCE between what the index holds for some roots and what it should hold.
 *
 * A root that is unchanged costs two reads and no write: not a row version, not an index entry. A
 * root that changed stage has the stage copy on its key rows rewritten (only on the rows whose
 * stage differs), so the partial stage-zero indexes can serve the first stage without ever
 * touching the entry (MS-18). The caller owns the transaction: entry and key rows of one root
 * commit together.
 *
 * @internal
 */
final readonly class DiffWriter
{
    private const int CHUNK = 5000;

    public function __construct(private TableNames $names) {}

    /**
     * @param  list<string>  $rootIds  Every root that was recomputed, existing or not.
     * @param  array<string, RootContribution>  $desired  What those that exist should hold.
     */
    public function write(Connection $db, string $subjectType, array $rootIds, array $desired, DrainStats $stats): void
    {
        if ($rootIds === []) {
            return;
        }

        $idArray = Sql::arrayLiteral($rootIds);
        $ids = SubjectKey::arrayParameter();
        $entries = $this->names->quoted($this->names->entries());
        $keys = $this->names->quoted($this->names->keys());

        $currentEntries = [];

        foreach ($db->select(
            // `sort_at` as a timestamptz in text, the form the contribution carries, whether the column has a time zone or not.
            sprintf('SELECT "subject_id", "partition", "stage", "names", "sort_at"::timestamptz::text AS "sort_at" FROM %s WHERE "subject_type" = ? AND "subject_id" = ANY(%s)', $entries, $ids),
            [$subjectType, $idArray],
        ) as $row) {
            $currentEntries[(string) $row->subject_id] = $row;
        }

        $currentKeys = [];
        $subjectsWithKeys = [];

        foreach ($db->select(
            sprintf('SELECT "subject_id", "kind", "key", "value", "partition", "stage" FROM %s WHERE "subject_type" = ? AND "subject_id" = ANY(%s)', $keys, $ids),
            [$subjectType, $idArray],
        ) as $row) {
            $currentKeys[$row->subject_id."\0".$row->kind."\0".$row->key] = $row;
            $subjectsWithKeys[(string) $row->subject_id] = true;
        }

        $goneIds = [];
        $entryRows = [];
        $keyRows = [];
        $keyDeletes = [];
        $stageRewrites = 0;
        $keyWrites = 0;
        $seen = [];

        foreach ($rootIds as $id) {
            $contribution = $desired[$id] ?? null;

            if ($contribution === null) {
                if (isset($currentEntries[$id]) || isset($subjectsWithKeys[$id])) {
                    $goneIds[] = $id;
                }

                continue;
            }

            $current = $currentEntries[$id] ?? null;

            if ($current === null
                || (int) $current->stage !== $contribution->stage
                || (string) $current->names !== $contribution->names
                || ($current->partition === null ? null : (string) $current->partition) !== $contribution->partition
                || ($current->sort_at === null ? null : (string) $current->sort_at) !== $contribution->sortAt) {
                $entryRows[] = $contribution;
            }

            foreach ($contribution->keys as $key) {
                $identity = $id."\0".$key['kind']."\0".$key['key'];
                $seen[$identity] = true;
                $existing = $currentKeys[$identity] ?? null;

                if ($existing === null) {
                    $keyRows[] = [$id, $contribution->partition, $key['kind'], $key['key'], $key['value'], $key['stage']];
                    $keyWrites++;

                    continue;
                }

                $stageChanged = (int) $existing->stage !== $key['stage'];
                $otherChanged = (string) $existing->value !== $key['value']
                    || ($existing->partition === null ? null : (string) $existing->partition) !== $contribution->partition;

                if ($stageChanged || $otherChanged) {
                    $keyRows[] = [$id, $contribution->partition, $key['kind'], $key['key'], $key['value'], $key['stage']];
                    $stageRewrites += $stageChanged ? 1 : 0;
                    $keyWrites += $stageChanged ? 0 : 1;
                }
            }
        }

        foreach ($currentKeys as $identity => $row) {
            if (! isset($seen[$identity]) && isset($desired[(string) $row->subject_id])) {
                $keyDeletes[] = [(string) $row->subject_id, (string) $row->kind, (string) $row->key];
            }
        }

        $goneEntries = 0;
        $keysDeleted = 0;

        if ($goneIds !== []) {
            $keysDeleted += $db->affectingStatement(sprintf('DELETE FROM %s WHERE "subject_type" = ? AND "subject_id" = ANY(%s)', $keys, $ids), [$subjectType, Sql::arrayLiteral($goneIds)]);
            $goneEntries = $db->affectingStatement(sprintf('DELETE FROM %s WHERE "subject_type" = ? AND "subject_id" = ANY(%s)', $entries, $ids), [$subjectType, Sql::arrayLiteral($goneIds)]);
        }

        foreach (array_chunk($keyDeletes, self::CHUNK) as $chunk) {
            $keysDeleted += $db->affectingStatement(
                sprintf('DELETE FROM %s AS k USING unnest(%s, ?::text[], ?::text[]) AS u("subject_id", "kind", "key") WHERE k."subject_type" = ? AND k."subject_id" = u."subject_id" AND k."kind" = u."kind" AND k."key" = u."key"', $keys, $ids),
                [Sql::arrayLiteral(array_column($chunk, 0)), Sql::arrayLiteral(array_column($chunk, 1)), Sql::arrayLiteral(array_column($chunk, 2)), $subjectType],
            );
        }

        foreach (array_chunk($entryRows, self::CHUNK) as $chunk) {
            $db->affectingStatement(
                sprintf(
                    'INSERT INTO %s ("subject_type", "subject_id", "partition", "stage", "names", "sort_at", "indexed_at") SELECT ?, u."subject_id", u."partition", u."stage", u."names", u."sort_at"::timestamptz, clock_timestamp() FROM unnest(%s, ?::text[], ?::smallint[], ?::text[], ?::text[]) AS u("subject_id", "partition", "stage", "names", "sort_at") ON CONFLICT ("subject_type", "subject_id") DO UPDATE SET "partition" = EXCLUDED."partition", "stage" = EXCLUDED."stage", "names" = EXCLUDED."names", "sort_at" = EXCLUDED."sort_at", "indexed_at" = EXCLUDED."indexed_at"',
                    $entries,
                    $ids,
                ),
                [
                    $subjectType,
                    Sql::arrayLiteral(array_map(static fn (RootContribution $row): string => $row->rootId, $chunk)),
                    Sql::arrayLiteral(array_map(static fn (RootContribution $row): ?string => $row->partition, $chunk)),
                    Sql::arrayLiteral(array_map(static fn (RootContribution $row): int => $row->stage, $chunk)),
                    Sql::arrayLiteral(array_map(static fn (RootContribution $row): string => $row->names, $chunk)),
                    Sql::arrayLiteral(array_map(static fn (RootContribution $row): ?string => $row->sortAt, $chunk)),
                ],
            );
        }

        foreach (array_chunk($keyRows, self::CHUNK) as $chunk) {
            $db->affectingStatement(
                sprintf(
                    'INSERT INTO %s ("subject_type", "subject_id", "partition", "kind", "key", "value", "stage") SELECT ?, u."subject_id", u."partition", u."kind", u."key", u."value", u."stage" FROM unnest(%s, ?::text[], ?::text[], ?::text[], ?::text[], ?::smallint[]) AS u("subject_id", "partition", "kind", "key", "value", "stage") ON CONFLICT ("subject_type", "subject_id", "kind", "key") DO UPDATE SET "partition" = EXCLUDED."partition", "value" = EXCLUDED."value", "stage" = EXCLUDED."stage"',
                    $keys,
                    $ids,
                ),
                [
                    $subjectType,
                    Sql::arrayLiteral(array_column($chunk, 0)),
                    Sql::arrayLiteral(array_column($chunk, 1)),
                    Sql::arrayLiteral(array_column($chunk, 2)),
                    Sql::arrayLiteral(array_column($chunk, 3)),
                    Sql::arrayLiteral(array_column($chunk, 4)),
                    Sql::arrayLiteral(array_column($chunk, 5)),
                ],
            );
        }

        $stats->keysDeleted += $keysDeleted;
        $stats->entriesDeleted += $goneEntries;
        $stats->entriesWritten += count($entryRows);
        $stats->keysWritten += $keyWrites;
        $stats->keyStageRewrites += $stageRewrites;

        if ($goneEntries + $keysDeleted + count($entryRows) + $keyWrites + $stageRewrites > 0) {
            $stats->touch($subjectType);
        }
    }
}
