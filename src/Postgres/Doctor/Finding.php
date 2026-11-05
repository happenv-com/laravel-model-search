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
     * @param  string  $code  Stable and public: new codes may appear in a minor release, an existing one is not renamed before a major. Hosts route on it.
     * @param  string|null  $subject  The type, index, table, node type or setting the finding is about; null when it is about none (`tables-missing`, `lag`, `scan-*`, a logging `privacy` violation).
     */
    public function __construct(public string $code, public string $message, public ?string $subject = null) {}
}
