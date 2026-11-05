<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

/**
 * The comment the package puts on every trigger, function and index it owns.
 *
 * `model-search:<plan>` for an index; `model-search:<plan>:<definition>` for a trigger and a
 * function, where `<plan>` is the hash of the statement that created the object and
 * `<definition>` the md5 of the object's definition as PostgreSQL itself reads it back right after
 * the install. The marker identifies what the sync may touch; the two hashes detect an object that
 * no longer matches the plan (a changed plan) or was edited in place behind the comment (a changed
 * live definition: CREATE OR REPLACE keeps the object, and with it the comment).
 *
 * @internal
 */
final class Marker
{
    public const string PREFIX = 'model-search:';

    public static function of(string $sql, ?string $definitionMd5 = null): string
    {
        return self::PREFIX.hash('sha256', $sql).($definitionMd5 === null ? '' : ':'.$definitionMd5);
    }

    /**
     * @return array{plan: string, definition: string|null}|null Null when the comment is not ours.
     */
    public static function parse(mixed $comment): ?array
    {
        if (! is_string($comment) || ! str_starts_with($comment, self::PREFIX)) {
            return null;
        }

        $parts = explode(':', substr($comment, strlen(self::PREFIX)));

        return ['plan' => $parts[0], 'definition' => $parts[1] ?? null];
    }
}
