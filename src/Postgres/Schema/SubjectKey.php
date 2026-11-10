<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;

/**
 * The column type of `subject_id` in the index tables (`model-search.subject_key_type`) and the SQL that follows from it.
 *
 * Every statement that binds, casts or orders subject ids takes the type from here, so the tables may come from the
 * package's migration or from the application's own copy, as long as the setting names the type the column has. The
 * change log is not affected: a node can be a row of any watched table, so `node_id` stays `text`.
 *
 * @internal
 */
final class SubjectKey
{
    /** @var list<'bigint'|'uuid'|'text'> */
    public const array TYPES = ['bigint', 'uuid', 'text'];

    /**
     * Catalog types of a model key that fit each subject key type. `text` takes any key, cast to its text form.
     *
     * @var array<'bigint'|'uuid', list<string>>
     */
    private const array FITTING_KEYS = [
        'bigint' => ['int8', 'int4', 'int2'],
        'uuid' => ['uuid'],
    ];

    /**
     * @return 'bigint'|'uuid'|'text'
     *
     * @throws InvalidSearchDefinition when the setting names no supported type
     */
    public static function type(): string
    {
        $type = config('model-search.subject_key_type', 'bigint');

        foreach (self::TYPES as $supported) {
            if ($type === $supported) {
                return $supported;
            }
        }

        throw new InvalidSearchDefinition(sprintf(
            'model-search.subject_key_type must be one of %s, %s given.',
            implode(', ', self::TYPES),
            is_scalar($type) ? '"'.$type.'"' : get_debug_type($type),
        ));
    }

    /**
     * A bound array literal of subject ids: `?::uuid[]`.
     */
    public static function arrayParameter(): string
    {
        return '?::'.self::type().'[]';
    }

    /**
     * An expression cast to the subject id type: `s."id"::uuid`.
     */
    public static function cast(string $expression): string
    {
        return $expression.'::'.self::type();
    }

    /**
     * What makes an order on subject ids the same on every server: text sorts by collation, so it is pinned to "C";
     * numbers and uuids sort the same everywhere and take no collation.
     */
    public static function collation(): string
    {
        return self::type() === 'text' ? ' COLLATE "C"' : '';
    }

    /**
     * Whether a model whose key column has this catalog type (`uuid`, `int8`, `text`, …) can be indexed.
     */
    public static function fits(string $keySqlType): bool
    {
        $type = self::type();

        return $type === 'text' || in_array($keySqlType, self::FITTING_KEYS[$type], true);
    }
}
