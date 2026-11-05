<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Normalizers;

use Closure;
use Happenv\ModelSearch\Normalization\KeyNormalizer;
use Happenv\ModelSearch\Normalization\Normalizer;

/**
 * Normalizes like a key, after calling a hook the test supplies: the hook may throw (a poison
 * record) or act on another connection while a batch is in flight.
 */
final class HookedNormalizer implements Normalizer
{
    /** @var (Closure(string): void)|null */
    public static ?Closure $hook = null;

    public function normalize(#[\SensitiveParameter] string $value): string
    {
        if (self::$hook instanceof Closure) {
            (self::$hook)($value);
        }

        return new KeyNormalizer()->normalize($value);
    }

    public function version(): int
    {
        return 1;
    }
}
