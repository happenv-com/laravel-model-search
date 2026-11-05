<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Contracts;

/**
 * The default observer: does nothing.
 */
final class NullSearchTermObserver implements SearchTermObserver
{
    #[\Override]
    public function observe(#[\SensitiveParameter] string $term): void {}
}
