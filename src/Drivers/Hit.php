<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Drivers;

use Happenv\ModelSearch\Definition\Mode;

/**
 * One row of a database driver search: the best way a record matched.
 *
 * @internal
 */
final readonly class Hit
{
    /** Added to the level of a hit through a parent, so it ranks below every direct hit. */
    public const int VIA_OFFSET = 10;

    public function __construct(
        public string $rootId,
        public string $kind,
        public int $level,
        public ?string $value,
        public bool $inStage,
        public ?string $via = null,
        public bool $hasNonExact = false,
    ) {}

    /**
     * The mode of the hit; a hit through a parent keeps the mode of the parent's match.
     */
    public function mode(): Mode
    {
        return Mode::fromRank($this->level % self::VIA_OFFSET);
    }

    public static function fromRow(object $row): self
    {
        $columns = (array) $row;
        $value = $columns['value'] ?? null;
        $kind = $columns['kind'] ?? '';
        $rootId = $columns['root_id'] ?? '';
        $level = $columns['level'] ?? 0;
        $inStage = $columns['in_stage'] ?? 0;
        $via = $columns['via'] ?? null;
        $nonExact = $columns['has_non_exact'] ?? 0;

        return new self(
            is_scalar($rootId) ? (string) $rootId : '',
            is_scalar($kind) ? (string) $kind : '',
            is_numeric($level) ? (int) $level : 0,
            is_scalar($value) ? (string) $value : null,
            is_numeric($inStage) && (int) $inStage === 1,
            is_string($via) ? $via : null,
            is_numeric($nonExact) && (int) $nonExact === 1,
        );
    }
}
