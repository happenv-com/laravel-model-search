<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Query;

use Illuminate\Database\Eloquent\Model;

final readonly class SearchResult
{
    public function __construct(
        public Model $model,
        public SearchMatch $match,
    ) {}
}
