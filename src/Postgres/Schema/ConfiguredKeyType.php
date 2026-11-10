<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;

/**
 * A key column of the index tables whose type a setting names, and the SQL that follows from it: `bigint` (Laravel's
 * `id()`), `uuid` (`HasUuids`) or `text` (any key, cast to its text form). Every statement that binds, casts or orders
 * those keys takes the type from here, so the tables may come from the package's migration or from the application's
 * own copy, as long as the setting names the type the column has.
 *
 * @internal
 */
abstract class ConfiguredKeyType
{
    /** @var list<'bigint'|'uuid'|'text'> */
    public const array TYPES = ['bigint', 'uuid', 'text'];

    /**
     * Catalog types of a key column that fit each type. `text` takes any key.
     *
     * @var array<'bigint'|'uuid', list<string>>
     */
    private const array FITTING_KEYS = [
        'bigint' => ['int8', 'int4', 'int2'],
        'uuid' => ['uuid'],
    ];

    /**
     * The setting under `model-search.` that names the type.
     */
    abstract public static function setting(): string;

    /**
     * @return 'bigint'|'uuid'|'text'
     */
    abstract protected static function defaultType(): string;

    /**
     * @return 'bigint'|'uuid'|'text'
     *
     * @throws InvalidSearchDefinition when the setting names no supported type
     */
    public static function type(): string
    {
        $type = config('model-search.'.static::setting(), static::defaultType());

        foreach (self::TYPES as $supported) {
            if ($type === $supported) {
                return $supported;
            }
        }

        throw new InvalidSearchDefinition(sprintf(
            'model-search.%s must be one of %s, %s given.',
            static::setting(),
            implode(', ', self::TYPES),
            is_scalar($type) ? '"'.$type.'"' : get_debug_type($type),
        ));
    }

    /**
     * A bound array literal of keys: `?::uuid[]`.
     */
    public static function arrayParameter(): string
    {
        return '?::'.static::type().'[]';
    }

    /**
     * An expression cast to the key type: `s."id"::uuid`.
     */
    public static function cast(string $expression): string
    {
        return $expression.'::'.static::type();
    }

    /**
     * What makes an order on the keys the same on every server: text sorts by collation, so it is pinned to "C";
     * numbers and uuids sort the same everywhere and take no collation.
     */
    public static function collation(): string
    {
        return static::type() === 'text' ? ' COLLATE "C"' : '';
    }

    /**
     * Whether a key column with this catalog type (`uuid`, `int8`, `text`, …) fits.
     */
    public static function fits(string $keySqlType): bool
    {
        $type = static::type();

        return $type === 'text' || in_array($keySqlType, self::FITTING_KEYS[$type], true);
    }
}
