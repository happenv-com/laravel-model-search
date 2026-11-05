<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Query;

use Happenv\ModelSearch\Definition\Mode;

/**
 * How a record matched: by which field kind, in which mode, and (unless the viewer may not see
 * it) the value that matched.
 */
final readonly class SearchMatch
{
    /**
     * @param  string|null  $stage  The stage key the record falls in; null when stages do not apply.
     * @param  string|null  $via  The relation through which the record was found; null for a direct hit.
     * @param  list<SearchMatch>  $alternatives  Other ways the same record matched.
     */
    public function __construct(
        public string $kind,
        public string $label,
        public ?string $value,
        public Mode $mode,
        public ?string $stage = null,
        public ?string $via = null,
        public array $alternatives = [],
    ) {}

    public function withValue(?string $value): self
    {
        return new self($this->kind, $this->label, $value, $this->mode, $this->stage, $this->via, $this->alternatives);
    }

    /**
     * @param  list<SearchMatch>  $alternatives
     */
    public function withAlternatives(array $alternatives): self
    {
        return new self($this->kind, $this->label, $this->value, $this->mode, $this->stage, $this->via, $alternatives);
    }
}
