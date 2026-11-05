<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

use Closure;
use Happenv\ModelSearch\Exceptions\InvalidFieldPath;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Exceptions\ReservedKind;
use Happenv\ModelSearch\Exceptions\UnsupportedMode;
use Happenv\ModelSearch\Normalization\Normalizer;

final class SearchField
{
    /** The kind under which the name words of a record are stored; fields may not claim it. */
    public const string RESERVED_KIND = 'name_word';

    /** @var list<Mode> */
    private array $modes;

    private string $kind;

    /** @var (Closure(): string)|null */
    private ?Closure $label = null;

    private bool $exactOnSource = false;

    private ?Normalizer $normalizer = null;

    private bool $translatable = false;

    private ?Closure $reveal = null;

    private int $weight = 1;

    /**
     * @param  list<string>  $kinds
     */
    private function __construct(
        private readonly FieldType $type,
        private readonly string $path,
        private readonly array $kinds = [],
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $path) !== 1) {
            throw new InvalidFieldPath(sprintf('"%s" is not a valid field path.', $path));
        }

        $this->modes = match ($type) {
            FieldType::Key => [Mode::Exact, Mode::Prefix, Mode::Suffix],
            FieldType::Name => [Mode::Prefix, Mode::Fuzzy],
            FieldType::Via, FieldType::Text => [],
        };

        $segments = explode('.', $path);
        $this->kind = end($segments);
        $this->guardKind($this->kind);
    }

    /**
     * An identifier: matched exactly, by prefix and by suffix.
     */
    public static function key(string $path): self
    {
        return new self(FieldType::Key, $path);
    }

    /**
     * A human, company or product name: matched by the words it contains, and fuzzily on demand.
     */
    public static function name(string $path): self
    {
        return new self(FieldType::Name, $path);
    }

    /**
     * Finds this record through a related record that matches (one hop).
     *
     * @param  list<string>  $kinds  Kinds of the related record's fields to follow; empty means all.
     */
    public static function via(string $relation, array $kinds = []): self
    {
        if (str_contains($relation, '.')) {
            throw new InvalidFieldPath(sprintf('A via field follows a single relation, "%s" is a path.', $relation));
        }

        return new self(FieldType::Via, $relation, $kinds);
    }

    /**
     * Long text. Full-text matching is a named seam that is not implemented yet.
     */
    public static function text(string $path): never
    {
        throw new UnsupportedMode(sprintf('Full-text matching is not supported yet, so text("%s") cannot be declared.', $path));
    }

    public function matches(Mode ...$modes): self
    {
        if ($this->type === FieldType::Via) {
            throw new UnsupportedMode('A via field follows the modes of the related field; it cannot narrow them.');
        }

        if ($modes === []) {
            throw new UnsupportedMode(sprintf('Field "%s" needs at least one mode.', $this->path));
        }

        $allowed = match ($this->type) {
            FieldType::Key => [Mode::Exact, Mode::Prefix, Mode::Suffix, Mode::Infix],
            default => [Mode::Prefix, Mode::Fuzzy],
        };

        foreach ($modes as $mode) {
            if (! in_array($mode, $allowed, true)) {
                throw new UnsupportedMode(sprintf(
                    'Mode "%s" is not supported on a %s field ("%s").',
                    $mode->value,
                    $this->type->value,
                    $this->path,
                ));
            }
        }

        $this->modes = array_values(array_unique($modes, SORT_REGULAR));

        return $this;
    }

    public function kind(string $kind): self
    {
        $this->guardKind($kind);
        $this->kind = $kind;

        return $this;
    }

    /**
     * @param  Closure(): string  $label
     */
    public function label(Closure $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Also reads the column straight from the model's table for exact matches, so a record created
     * a second ago is found by its exact number before the index has seen it.
     */
    public function exactOnSource(): self
    {
        if ($this->type !== FieldType::Key) {
            throw new InvalidSearchDefinition('exactOnSource() is only available on key fields.');
        }

        if (str_contains($this->path, '.')) {
            throw new InvalidSearchDefinition(sprintf(
                'exactOnSource() reads the model\'s own column, but "%s" is on a relation path.',
                $this->path,
            ));
        }

        $this->exactOnSource = true;

        return $this;
    }

    public function normalizeUsing(Normalizer $normalizer): self
    {
        $this->normalizer = $normalizer;

        return $this;
    }

    public function translatable(): self
    {
        $this->translatable = true;

        return $this;
    }

    /**
     * @param  Closure(mixed, mixed): bool  $reveal  Receives the record and the viewer; false hides the matched value.
     */
    public function revealUsing(Closure $reveal): self
    {
        $this->reveal = $reveal;

        return $this;
    }

    public function weight(int $weight): self
    {
        $this->weight = $weight;

        return $this;
    }

    public function type(): FieldType
    {
        return $this->type;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function toCompiled(bool $declaredByExtension): CompiledField
    {
        return new CompiledField(
            type: $this->type,
            path: $this->path,
            kind: $this->kind,
            modes: $this->modes,
            via: $this->type === FieldType::Via ? $this->kinds : [],
            label: $this->label,
            exactOnSource: $this->exactOnSource,
            normalizer: $this->normalizer,
            translatable: $this->translatable,
            reveal: $this->reveal,
            weight: $this->weight,
            declaredByExtension: $declaredByExtension,
        );
    }

    private function guardKind(string $kind): void
    {
        if ($kind === self::RESERVED_KIND) {
            throw new ReservedKind(sprintf('The kind "%s" is reserved for the name words the package stores itself.', self::RESERVED_KIND));
        }

        if ($kind === '') {
            throw new InvalidSearchDefinition('A field kind may not be empty.');
        }
    }
}
