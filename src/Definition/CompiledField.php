<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

use Closure;
use Happenv\ModelSearch\Compilation\CompiledPath;
use Happenv\ModelSearch\Normalization\Normalizer;

/**
 * @internal
 */
final readonly class CompiledField
{
    /**
     * @param  list<Mode>  $modes
     * @param  list<string>  $via  For a via field: the kinds of the related record to follow.
     * @param  (Closure(): string)|null  $label
     */
    public function __construct(
        public FieldType $type,
        public string $path,
        public string $kind,
        public array $modes,
        public array $via,
        public ?Closure $label,
        public bool $exactOnSource,
        public ?Normalizer $normalizer,
        public bool $translatable,
        public ?Closure $reveal,
        public int $weight,
        public bool $declaredByExtension,
        public ?CompiledPath $compiledPath = null,
    ) {}

    public function withCompiledPath(CompiledPath $compiledPath): self
    {
        return new self(
            $this->type,
            $this->path,
            $this->kind,
            $this->modes,
            $this->via,
            $this->label,
            $this->exactOnSource,
            $this->normalizer,
            $this->translatable,
            $this->reveal,
            $this->weight,
            $this->declaredByExtension,
            $compiledPath,
        );
    }
}
