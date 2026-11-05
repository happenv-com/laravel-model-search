<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Console;

use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use InvalidArgumentException;

/**
 * Turns the values of a `--subject` option (a morph alias or a model class) into morph aliases.
 *
 * @internal
 */
final class SubjectOption
{
    /**
     * @param  array<int, mixed>  $values
     * @return list<string>|null Null when the option was not given, meaning every type.
     */
    public static function aliases(ModelSearchRegistry $registry, array $values): ?array
    {
        $values = array_values(array_filter(array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $values), static fn (string $value): bool => $value !== ''));

        if ($values === []) {
            return null;
        }

        $known = [];

        foreach ($registry->all() as $definition) {
            $known[$definition->morphAlias] = $definition->morphAlias;
            $known[$definition->modelClass] = $definition->morphAlias;
        }

        $aliases = [];

        foreach ($values as $value) {
            if (! isset($known[$value])) {
                throw new InvalidArgumentException(sprintf('"%s" is not a registered search subject; use a morph alias or a model class that has a search definition.', $value));
            }

            $aliases[$known[$value]] = true;
        }

        return array_map(strval(...), array_keys($aliases));
    }
}
