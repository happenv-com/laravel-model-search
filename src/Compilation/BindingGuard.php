<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

use BackedEnum;
use DateTimeInterface;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Throwable;

/**
 * Index content may depend only on stored data. A bound value that moves with the clock (or is an
 * object the fingerprint cannot hash) would change what a query selects without any row changing,
 * so the index would go stale in silence.
 *
 * A time-relative value passed as a plain string (for example `now()->toDateString()`) cannot be
 * detected here and is equally wrong.
 *
 * @internal
 */
final class BindingGuard
{
    /**
     * @param  list<mixed>  $bindings
     * @param  class-string<Throwable>  $failure
     * @return list<bool|float|int|string|null> Scalars and null, backed enums replaced by their values.
     */
    public static function canonical(array $bindings, string $subject, string $failure = InvalidSearchDefinition::class): array
    {
        $canonical = [];

        foreach ($bindings as $binding) {
            if ($binding instanceof DateTimeInterface) {
                throw new $failure(sprintf('%s compares against a date or time, but it must depend only on stored data; a time-relative value goes stale without any row change.', $subject));
            }

            $binding = $binding instanceof BackedEnum ? $binding->value : $binding;

            if (! is_scalar($binding) && $binding !== null) {
                throw new $failure(sprintf('%s binds a value that is not a scalar, null or backed enum, but it must depend only on stored data.', $subject));
            }

            $canonical[] = $binding;
        }

        return $canonical;
    }
}
