<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

/**
 * The desired state of the derived schema objects, independent of what the database has now.
 *
 * @internal
 */
final readonly class SchemaPlan
{
    /**
     * @param  list<RenderedTriggers>  $triggers
     * @param  list<IndexSpec>  $indexes
     */
    public function __construct(
        public string $schema,
        public array $triggers,
        public array $indexes,
    ) {}
}
