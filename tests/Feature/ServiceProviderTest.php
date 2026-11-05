<?php

declare(strict_types=1);

use Happenv\ModelSearch\ModelSearchServiceProvider;

covers(ModelSearchServiceProvider::class);

it('boots the provider and merges config', function (): void {
    expect(config('model-search.table_prefix'))->toBe('model_search_')
        ->and(config('model-search.pool'))->toBe(200);
});
