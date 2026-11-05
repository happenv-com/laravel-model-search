<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

use BackedEnum;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\CompiledField;
use Happenv\ModelSearch\Definition\FieldType;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Exceptions\InvalidStageConstraint;
use Happenv\ModelSearch\Normalization\KeyNormalizer;
use Happenv\ModelSearch\Normalization\NameNormalizer;
use Happenv\ModelSearch\Normalization\Normalizer;

/**
 * A hash of everything that changes the CONTENT of a definition's index, and nothing else.
 *
 * Labels, weights, reveal rules and anything else that only changes how a result is presented are
 * left out on purpose: they must not trigger a rebuild. PHP logic cannot be hashed, so a custom
 * normalizer (or anything else the SQL cannot see) is covered by its version() and by the
 * definition's own version().
 *
 * @internal
 */
final class Fingerprint
{
    public static function of(CompiledDefinition $definition): string
    {
        $fields = array_map(self::field(...), $definition->fields);
        usort($fields, static fn (array $left, array $right): int => strcmp((string) $left['kind'], (string) $right['kind']));

        $payload = [
            'morph_alias' => $definition->morphAlias,
            'key_type' => $definition->keyType,
            'partition' => $definition->partition ?? config('model-search.partition_column'),
            'sort_at' => $definition->sortAt,
            'fields' => $fields,
            'stages' => self::stages($definition),
            'name_words' => [
                'min_length' => config('model-search.name_words.min_length'),
                'max_per_record' => config('model-search.name_words.max_per_record'),
            ],
            'version' => $definition->version,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The stage SQL with its bindings canonicalised: scalars and enum values only.
     *
     * @return array{sql: string, bindings: list<bool|float|int|string|null>}
     */
    private static function stages(CompiledDefinition $definition): array
    {
        $query = $definition->stageSql([]);
        $bindings = [];

        foreach ($query['bindings'] as $binding) {
            $binding = $binding instanceof BackedEnum ? $binding->value : $binding;

            if (! is_scalar($binding) && $binding !== null) {
                throw new InvalidStageConstraint('A stage binds a value that cannot be fingerprinted; a stage must depend only on scalars, null and backed enums.');
            }

            $bindings[] = $binding;
        }

        return ['sql' => $query['sql'], 'bindings' => $bindings];
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(CompiledField $field): array
    {
        $modes = array_map(static fn (Mode $mode): string => $mode->value, $field->modes);
        sort($modes);

        $via = $field->via;
        sort($via);

        return [
            'type' => $field->type->value,
            'path' => $field->path,
            'kind' => $field->kind,
            'modes' => $modes,
            'via' => $via,
            'translatable' => $field->translatable,
            'normalizer' => self::normalizer($field),
            'contribution' => self::contribution($field),
        ];
    }

    /**
     * @return array{sql: string, bindings: list<bool|float|int|string|null>}|null
     */
    private static function contribution(CompiledField $field): ?array
    {
        $query = $field->compiledPath?->contributionSql([]);

        return $query === null ? null : ['sql' => $query['sql'], 'bindings' => BindingGuard::canonical($query['bindings'], 'A field path')];
    }

    /**
     * @return array{class: string, version: int}|null
     */
    private static function normalizer(CompiledField $field): ?array
    {
        $normalizer = $field->normalizer ?? match ($field->type) {
            FieldType::Key => new KeyNormalizer,
            FieldType::Name => new NameNormalizer,
            default => null,
        };

        if (! $normalizer instanceof Normalizer) {
            return null;
        }

        return ['class' => get_debug_type($normalizer), 'version' => $normalizer->version()];
    }
}
