<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

use Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Names the model a search definition belongs to. Checked when the definition is registered.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SearchesModel
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(public string $model) {}
}
