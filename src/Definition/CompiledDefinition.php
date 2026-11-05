<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

use Illuminate\Database\Eloquent\Model;

final readonly class CompiledDefinition
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  'uuid'|'bigint'|'text'  $keyType
     * @param  list<CompiledField>  $fields
     * @param  list<SearchStage>  $stages  Always ends with {@see SearchStage::all()}.
     * @param  list<class-string>  $withScopes
     */
    public function __construct(
        public string $modelClass,
        public string $morphAlias,
        public string $keyType,
        public array $fields,
        public array $stages,
        public ?string $sortAt,
        public ?string $partition,
        public bool $fallback = false,
        public int $version = 1,
        public array $withScopes = [],
    ) {}

    /**
     * Whether the definition narrows anything besides the final "all" stage.
     */
    public function hasStages(): bool
    {
        return array_any($this->stages, fn (SearchStage $stage): bool => ! $stage->isAll());
    }

    public function hasNames(): bool
    {
        return array_any($this->fields, fn (CompiledField $field): bool => $field->type === FieldType::Name);
    }
}
