<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

/**
 * The column type of `subject_id` in the entries, keys and failures tables (`model-search.subject_key_type`, `bigint` by
 * default): the key of every searched record.
 *
 * @internal
 */
final class SubjectKey extends ConfiguredKeyType
{
    public static function setting(): string
    {
        return 'subject_key_type';
    }

    protected static function defaultType(): string
    {
        return 'bigint';
    }
}
