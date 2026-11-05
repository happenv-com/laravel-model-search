<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides addresses without a name, the way a tenant or visibility scope would hide rows.
 */
final class NamedAddressScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    #[\Override]
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNotNull($model->qualifyColumn('name'));
    }
}
