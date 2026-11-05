<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Doctor;

/**
 * One thing the doctor found wrong. The package does not alarm: the host decides what to do with it.
 *
 * The message names types, tables, indexes and settings and carries counts; it never carries a
 * phrase or a record's value.
 */
final readonly class Finding
{
    /**
     * @param  string  $code  Stable, for the host to route on (`lag`, `index-missing`, `privacy`, ...).
     * @param  string|null  $subject  The type, index or setting the finding is about.
     */
    public function __construct(public string $code, public string $message, public ?string $subject = null) {}
}
