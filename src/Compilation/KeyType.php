<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
final class KeyType
{
    /**
     * @return 'uuid'|'bigint'|'text'
     */
    public static function of(Model $model): string
    {
        if ($model->getKeyType() === 'int') {
            return 'bigint';
        }

        return in_array(HasUuids::class, class_uses_recursive($model), true) ? 'uuid' : 'text';
    }

    /**
     * The type of one column of a model: only its primary key is known, everything else is compared as text.
     *
     * @return 'uuid'|'bigint'|'text'
     */
    public static function ofColumn(Model $model, string $column): string
    {
        return $column === $model->getKeyName() ? self::of($model) : 'text';
    }
}
