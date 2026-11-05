<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Query;

use Closure;

/**
 * Lets the queries of one palette share a single rate-limit charge: the first to ask pays, the
 * others find it paid. A palette that runs no statement never asks, so it costs nothing.
 *
 * @internal
 */
final class ChargeOnce
{
    private bool $charged = false;

    /**
     * @param  Closure(): void  $charge
     */
    public function once(Closure $charge): void
    {
        if ($this->charged) {
            return;
        }

        $charge();
        $this->charged = true;
    }
}
