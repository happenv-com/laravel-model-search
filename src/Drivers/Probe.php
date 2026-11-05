<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Drivers;

use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Query\SearchQuery;

/**
 * What a search looks for: the phrase and the narrowing that decides which fields and modes may serve it.
 *
 * @internal
 */
final readonly class Probe
{
    /**
     * @param  list<string>  $kinds  Empty means every kind.
     * @param  list<Mode>  $modes  Empty means every mode.
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $phrase,
        public ?string $locale,
        public array $kinds,
        public array $modes,
    ) {}

    public static function of(SearchQuery $query): self
    {
        return new self($query->phraseText(), $query->viewerLocale(), $query->onlyKinds(), $query->onlyModes());
    }

    /**
     * The same phrase, for the fields of another model that are named by kind.
     *
     * @param  list<string>  $kinds
     */
    public function forKinds(array $kinds): self
    {
        return new self($this->phrase, $this->locale, $kinds, $this->modes);
    }
}
