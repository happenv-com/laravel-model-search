<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Events;

/**
 * Dispatched when the oldest unprocessed change has waited longer than `lag_alarm_seconds`.
 */
final readonly class SearchIndexLagging
{
    public function __construct(public int $lagSeconds, public int $alarmSeconds) {}
}
