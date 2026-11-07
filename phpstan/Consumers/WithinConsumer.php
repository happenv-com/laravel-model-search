<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\PhpStan\Consumers;

use Happenv\ModelSearch\Query\SearchQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Exists only so PHPStan checks that {@see SearchQuery::within()} takes the builder of a concrete model, as an
 * application passes it (`Order::query()`), not only `Builder<Model>`. Not autoloaded and not shipped.
 */
final class WithinConsumer extends Model
{
    /**
     * @param  Builder<self>  $records
     */
    public function scopeWithin(SearchQuery $query, Builder $records): SearchQuery
    {
        return $query->within($records, [self::class => $records]);
    }
}
