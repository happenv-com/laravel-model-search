<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

/**
 * The model a path starts from.
 *
 * @internal
 */
final readonly class PathRoot
{
    /**
     * @param  'uuid'|'bigint'|'text'  $keyType
     * @param  array{sql: string, bindings: list<mixed>}|null  $derived  The table seen through the listed global scopes.
     */
    public function __construct(
        public string $quotedTable,
        public string $keyColumn,
        public string $keyType,
        public ?string $softDeleteColumn,
        public ?array $derived,
        public string $nodeType,
    ) {}
}
