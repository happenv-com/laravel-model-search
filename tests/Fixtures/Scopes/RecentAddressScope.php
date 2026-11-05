<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A scope that depends on the clock, which an index cannot follow.
 */
final class RecentAddressScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    #[\Override]
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('updated_at'), '>=', now()->subDay());
    }
}
