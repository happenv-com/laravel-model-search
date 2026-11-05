<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Registry;

use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\CompiledField;
use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\ModelSearchExtension;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Definition\SearchStage;
use Happenv\ModelSearch\Definition\StageConstraintInspector;
use Happenv\ModelSearch\Exceptions\DuplicateDefinition;
use Happenv\ModelSearch\Exceptions\DuplicateKind;
use Happenv\ModelSearch\Exceptions\ExtensionWithoutDefinition;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Exceptions\UnknownDefinition;
use Happenv\ModelSearch\Exceptions\UnsupportedStages;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Holds the registered definitions and extensions and compiles them lazily, once per process.
 */
final class ModelSearchRegistry
{
    /** @var array<class-string<Model>, class-string<ModelSearch>> */
    private array $definitions = [];

    /** @var array<class-string<Model>, list<class-string<ModelSearchExtension>>> */
    private array $extensions = [];

    /** @var array<class-string<Model>, CompiledDefinition> */
    private array $compiled = [];

    /**
     * @param  class-string<ModelSearch>  $definition
     */
    public function register(string $definition): void
    {
        if (! is_subclass_of($definition, ModelSearch::class)) {
            throw new InvalidSearchDefinition(sprintf('%s must extend %s.', $definition, ModelSearch::class));
        }

        $model = $definition::model();

        if (! is_subclass_of($model, Model::class)) {
            throw new InvalidSearchDefinition(sprintf('%s searches %s, which is not an Eloquent model.', $definition, $model));
        }

        $existing = $this->definitions[$model] ?? null;

        if ($existing === $definition) {
            return;
        }

        if ($existing !== null) {
            throw new DuplicateDefinition(sprintf('%s already has a search definition (%s); %s cannot be registered as a second one.', $model, $existing, $definition));
        }

        $this->definitions[$model] = $definition;
        $this->compiled = [];
    }

    /**
     * @param  class-string<ModelSearchExtension>  $extension
     */
    public function extend(string $extension): void
    {
        if (! is_subclass_of($extension, ModelSearchExtension::class)) {
            throw new InvalidSearchDefinition(sprintf('%s must extend %s.', $extension, ModelSearchExtension::class));
        }

        $model = (new $extension)->model();

        if (in_array($extension, $this->extensions[$model] ?? [], true)) {
            return;
        }

        $this->extensions[$model][] = $extension;
        $this->compiled = [];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function for(string $modelClass): CompiledDefinition
    {
        if (isset($this->compiled[$modelClass])) {
            return $this->compiled[$modelClass];
        }

        $definition = $this->definitions[$modelClass] ?? null;

        if ($definition === null) {
            throw isset($this->extensions[$modelClass])
                ? new ExtensionWithoutDefinition(sprintf('%s is extended by %s but has no search definition.', $modelClass, implode(', ', $this->extensions[$modelClass])))
                : new UnknownDefinition(sprintf('%s has no search definition.', $modelClass));
        }

        return $this->compiled[$modelClass] = $this->compile($modelClass, new $definition);
    }

    /**
     * Compiles every registration, so a dangling extension or a broken definition surfaces here.
     *
     * @return array<class-string<Model>, CompiledDefinition>
     */
    public function all(): array
    {
        foreach (array_keys($this->extensions) as $model) {
            if (! isset($this->definitions[$model])) {
                throw new ExtensionWithoutDefinition(sprintf('%s is extended by %s but has no search definition.', $model, implode(', ', $this->extensions[$model])));
            }
        }

        $all = [];

        foreach (array_keys($this->definitions) as $model) {
            $all[$model] = $this->for($model);
        }

        return $all;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function compile(string $modelClass, ModelSearch $definition): CompiledDefinition
    {
        $model = new $modelClass;

        $fields = $this->compileFields($modelClass, $definition);
        $stages = $this->compileStages($modelClass, $definition);

        return new CompiledDefinition(
            modelClass: $modelClass,
            morphAlias: $model->getMorphClass(),
            keyType: $this->keyType($model),
            fields: $fields,
            stages: $stages,
            sortAt: $definition->sortAt(),
            partition: $definition->partition(),
            fallback: $definition->fallback(),
            version: $definition->version(),
            withScopes: $definition->withScopes(),
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<CompiledField>
     */
    private function compileFields(string $modelClass, ModelSearch $definition): array
    {
        $fields = array_map(
            static fn (SearchField $field): CompiledField => $field->toCompiled(false),
            $definition->fields(),
        );

        foreach ($this->extensions[$modelClass] ?? [] as $extension) {
            foreach ((new $extension)->fields() as $field) {
                $fields[] = $field->toCompiled(true);
            }
        }

        if ($fields === []) {
            throw new InvalidSearchDefinition(sprintf('The search definition of %s declares no fields.', $modelClass));
        }

        $seen = [];

        foreach ($fields as $field) {
            if (isset($seen[$field->kind])) {
                throw new DuplicateKind(sprintf(
                    'The kind "%s" is declared twice in the search definition of %s ("%s" and "%s"); give one of them a distinct kind().',
                    $field->kind,
                    $modelClass,
                    $seen[$field->kind],
                    $field->path,
                ));
            }

            $seen[$field->kind] = $field->path;
        }

        return $fields;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<SearchStage>
     */
    private function compileStages(string $modelClass, ModelSearch $definition): array
    {
        $stages = $definition->stages();
        $last = count($stages) - 1;
        $constrained = [];

        foreach ($stages as $index => $stage) {
            if ($stage->isAll()) {
                if ($index !== $last) {
                    throw new UnsupportedStages('SearchStage::all() must be the last stage.');
                }

                continue;
            }

            $constrained[] = $stage;
        }

        if (count($constrained) > 1) {
            throw new UnsupportedStages(sprintf(
                'The search definition of %s declares %d stages besides all(); only one is supported.',
                $modelClass,
                count($constrained),
            ));
        }

        $inspector = new StageConstraintInspector;

        foreach ($constrained as $stage) {
            $inspector->inspect($modelClass, $stage);
        }

        if ($stages === [] || ! $stages[$last]->isAll()) {
            $stages[] = SearchStage::all();
        }

        return $stages;
    }

    /**
     * @return 'uuid'|'bigint'|'text'
     */
    private function keyType(Model $model): string
    {
        if ($model->getKeyType() === 'int') {
            return 'bigint';
        }

        return in_array(HasUuids::class, class_uses_recursive($model), true) ? 'uuid' : 'text';
    }
}
