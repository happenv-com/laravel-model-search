<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

/**
 * The comment the package puts on every trigger, function and index it owns: the marker and the
 * hash of the statement that created the object. It identifies the objects the sync may drop and
 * detects an object that no longer matches the statement it was made from.
 */
final class Marker
{
    public const string PREFIX = 'model-search:';

    public static function of(string $sql): string
    {
        return self::PREFIX.hash('sha256', $sql);
    }
}
