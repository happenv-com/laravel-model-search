<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

use Illuminate\Database\Eloquent\Model;

/**
 * Adds fields to another party's definition. Names the model, not the definition class, so the
 * extending party does not depend on the owner and registration order does not matter.
 */
abstract class ModelSearchExtension
{
    /**
     * @return class-string<Model>
     */
    abstract public function model(): string;

    /**
     * @return list<SearchField>
     */
    abstract public function fields(): array;
}
