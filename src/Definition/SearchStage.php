<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

use Closure;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;

final class SearchStage
{
    public const string ALL = 'all';

    /** @var list<string> */
    private array $watching = [];

    /** @var (Closure(): string)|null */
    private ?Closure $label = null;

    private function __construct(
        public readonly string $key,
        public readonly ?Closure $constraint,
    ) {}

    /**
     * A stage narrows the model's query. Its constraint may only touch columns of the model's own
     * table, and every column it touches must be declared through {@see self::watching()}.
     */
    public static function make(string $key, Closure $constraint): self
    {
        if ($key === self::ALL) {
            throw new InvalidSearchDefinition(sprintf('The stage key "%s" is reserved for SearchStage::all().', self::ALL));
        }

        if ($key === '') {
            throw new InvalidSearchDefinition('A search stage needs a non-empty key.');
        }

        return new self($key, $constraint);
    }

    /**
     * The unconstrained last stage, present in every definition.
     */
    public static function all(): self
    {
        return new self(self::ALL, null);
    }

    /**
     * @param  list<string>  $columns
     */
    public function watching(array $columns): self
    {
        $this->watching = array_values(array_unique($columns));

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
     * @return list<string>
     */
    public function watchedColumns(): array
    {
        return $this->watching;
    }

    /**
     * @return (Closure(): string)|null
     */
    public function labelResolver(): ?Closure
    {
        return $this->label;
    }

    public function isAll(): bool
    {
        return ! $this->constraint instanceof Closure;
    }
}
