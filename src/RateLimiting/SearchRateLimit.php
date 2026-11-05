<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\RateLimiting;

use Happenv\ModelSearch\Exceptions\SearchRateLimited;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

/**
 * One bucket per viewer and database on Laravel's {@see RateLimiter}, configured by `model-search.rate_limit`:
 * `['max_attempts' => 30, 'decay_seconds' => 10]` (or the pair `[30, 10]`); `false`, `null` or a
 * maximum below 1 turns it off.
 *
 * A search without a viewer (a job, a console command) is not limited: there is nobody to charge it
 * to. Guard anonymous endpoints with the host's own throttle middleware.
 *
 * @internal
 */
final class SearchRateLimit
{
    /**
     * @throws SearchRateLimited Before any query is run.
     */
    public function attempt(?Authenticatable $viewer, string $database = ''): void
    {
        [$maxAttempts, $decaySeconds] = $this->limits();

        if (! $viewer instanceof Authenticatable || $maxAttempts < 1) {
            return;
        }

        $key = 'model-search:search:'.sha1($database.'|'.$viewer::class.'|'.$viewer->getAuthIdentifier());
        $limiter = $this->limiter();

        if ($limiter->tooManyAttempts($key, $maxAttempts)) {
            throw new SearchRateLimited($limiter->availableIn($key));
        }

        $limiter->hit($key, $decaySeconds);
    }

    /**
     * @return array{int, int} Maximum attempts and decay in seconds.
     */
    private function limits(): array
    {
        $config = config('model-search.rate_limit');

        if (! is_array($config)) {
            return [0, 0];
        }

        $max = $config['max_attempts'] ?? $config[0] ?? 0;
        $decay = $config['decay_seconds'] ?? $config[1] ?? 60;

        return [is_numeric($max) ? (int) $max : 0, is_numeric($decay) ? max(1, (int) $decay) : 60];
    }

    private function limiter(): RateLimiter
    {
        $store = config('model-search.cache_store');

        return is_string($store) ? new RateLimiter(Cache::store($store)) : resolve(RateLimiter::class);
    }
}
