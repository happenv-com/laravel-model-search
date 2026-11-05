<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Query;

use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\CompiledField;
use Happenv\ModelSearch\Definition\FieldType;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Exceptions\UnsupportedQuery;

/**
 * The name words of a record do not record which name field they came from, so a query cannot
 * restrict itself to some of the prefix name fields of a definition. Naming all of them, or none, is fine.
 *
 * @internal
 */
final class NameFieldSelection
{
    /**
     * @param  list<string>  $kinds  What `only()` named; empty means every kind.
     *
     * @throws UnsupportedQuery
     */
    public static function assertWhole(CompiledDefinition $definition, array $kinds): void
    {
        if ($kinds === []) {
            return;
        }

        $names = array_values(array_map(
            static fn (CompiledField $field): string => $field->kind,
            array_filter($definition->fields, static fn (CompiledField $field): bool => $field->type === FieldType::Name && in_array(Mode::Prefix, $field->modes, true)),
        ));
        $named = array_intersect($names, $kinds);

        if ($named !== [] && count($named) < count($names)) {
            throw new UnsupportedQuery(sprintf(
                'only() names some of the name fields of %s but not all of them; name words do not record which field they came from, so name all of them or none.',
                $definition->modelClass,
            ));
        }
    }
}
