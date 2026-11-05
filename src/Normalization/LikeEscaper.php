<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Normalization;

/**
 * Escapes a value for a PostgreSQL `LIKE ... ESCAPE '\'` pattern so that `%`, `_` and `\` match
 * literally.
 */
final class LikeEscaper
{
    public static function escape(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
