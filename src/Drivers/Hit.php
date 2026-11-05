<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Drivers;

/**
 * One row of a database driver search: the best way a record matched.
 *
 * @internal
 */
final readonly class Hit
{
    public function __construct(
        public string $rootId,
        public string $kind,
        public int $level,
        public ?string $value,
        public bool $inStage,
    ) {}

    public static function fromRow(object $row): self
    {
        $columns = (array) $row;
        $value = $columns['value'] ?? null;
        $kind = $columns['kind'] ?? '';
        $rootId = $columns['root_id'] ?? '';
        $level = $columns['level'] ?? 0;
        $inStage = $columns['in_stage'] ?? 0;

        return new self(
            is_scalar($rootId) ? (string) $rootId : '',
            is_scalar($kind) ? (string) $kind : '',
            is_numeric($level) ? (int) $level : 0,
            is_scalar($value) ? (string) $value : null,
            is_numeric($inStage) && (int) $inStage === 1,
        );
    }
}
