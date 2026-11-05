<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Query;

use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\CompiledField;
use Happenv\ModelSearch\Definition\FieldType;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies the `revealUsing()` rules of a definition to a match: the matched value, and every
 * alternative, is shown only if the field it came from allows the viewer to see it.
 *
 * A name word is a word of any name of the record whose field matches by prefix (only those have
 * their words indexed), so it is revealed only when EVERY such name field allows it. A kind the
 * definition does not know is hidden.
 *
 * @internal
 */
final readonly class RevealPolicy
{
    public function __construct(private CompiledDefinition $definition) {}

    public function apply(SearchMatch $match, Model $model, mixed $viewer): SearchMatch
    {
        $alternatives = array_values(array_filter(
            $match->alternatives,
            fn (SearchMatch $alternative): bool => $this->allows($alternative->kind, $model, $viewer),
        ));

        return $match
            ->withValue($this->allows($match->kind, $model, $viewer) ? $match->value : null)
            ->withAlternatives($alternatives);
    }

    /**
     * The value of a match that has no model to ask (a raw candidate): shown only for a field WITHOUT a
     * `revealUsing()` rule, since a rule needs the model and the viewer. The name words answer for the
     * name fields that match by prefix.
     */
    public static function withoutModel(CompiledDefinition $definition, string $kind, ?string $value): ?string
    {
        $fields = $kind === SearchField::RESERVED_KIND
            ? array_filter($definition->fields, static fn (CompiledField $field): bool => $field->type === FieldType::Name && in_array(Mode::Prefix, $field->modes, true))
            : array_filter($definition->fields, static fn (CompiledField $field): bool => $field->kind === $kind);

        return $fields !== [] && array_all($fields, static fn (CompiledField $field): bool => ! $field->reveal instanceof \Closure) ? $value : null;
    }

    private function allows(string $kind, Model $model, mixed $viewer): bool
    {
        if ($kind === SearchField::RESERVED_KIND) {
            $names = array_filter($this->definition->fields, static fn (CompiledField $field): bool => $field->type === FieldType::Name && in_array(Mode::Prefix, $field->modes, true));

            return $names !== [] && array_all($names, static fn ($field): bool => $field->reveal === null || (bool) ($field->reveal)($model, $viewer));
        }

        foreach ($this->definition->fields as $field) {
            if ($field->kind === $kind) {
                return $field->reveal === null || (bool) ($field->reveal)($model, $viewer);
            }
        }

        return false;
    }
}
