<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Query;

use Closure;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Drivers\Driver;
use Happenv\ModelSearch\Drivers\DriverManager;
use Happenv\ModelSearch\Exceptions\PartitionMissing;
use Happenv\ModelSearch\Exceptions\SearchScopeMissing;
use Happenv\ModelSearch\Exceptions\UnsupportedHostQuery;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A search of one model, built with immutable narrowing methods and run by one of four executors.
 *
 * It is fail-closed: nothing runs until the query says whose records it may see, either with
 * {@see self::within()} (the host's scope) or {@see self::withoutScope()} (an explicit, reasoned
 * opt-out). The phrase is private data: it never appears in an exception message or a stack trace.
 */
final class SearchQuery
{
    public const int DEFAULT_LIMIT = 5;

    /** @var list<string> */
    private array $kinds = [];

    /** @var list<Mode> */
    private array $modes = [];

    private ?HostQuery $host = null;

    private ?string $unscopedReason = null;

    private bool $stages = true;

    private int $limit = self::DEFAULT_LIMIT;

    private string|int|null $partition = null;

    private ?string $locale = null;

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function __construct(
        private readonly string $modelClass,
        #[\SensitiveParameter]
        private readonly string $phrase,
    ) {}

    /**
     * Limits the search to what the viewer may see: the host's own query of the model.
     *
     * @param  Builder<Model>  $host
     */
    public function within(Builder $host): self
    {
        if (! $host->getModel() instanceof $this->modelClass) {
            throw new UnsupportedHostQuery(sprintf('The host query is for %s, but this search is for %s.', $host->getModel()::class, $this->modelClass));
        }

        $scope = HostQuery::from($host);

        return $this->evolve(function (self $query) use ($scope): void {
            $query->host = $scope;
            $query->unscopedReason = null;
        });
    }

    /**
     * Searches every record of the model, for work that has no viewer. The reason is the audit trail.
     */
    public function withoutScope(string $reason): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Searching without a scope needs a reason.');
        }

        return $this->evolve(function (self $query) use ($reason): void {
            $query->host = null;
            $query->unscopedReason = $reason;
        });
    }

    /**
     * Only the fields with these kinds.
     *
     * @param  list<string>  $kinds
     */
    public function only(array $kinds): self
    {
        return $this->evolve(function (self $query) use ($kinds): void {
            $query->kinds = array_values(array_unique($kinds));
        });
    }

    /**
     * Only these modes, where the field supports them.
     */
    public function using(Mode ...$modes): self
    {
        return $this->evolve(function (self $query) use ($modes): void {
            $query->modes = array_values(array_unique($modes, SORT_REGULAR));
        });
    }

    /**
     * Searches every stage at once, in no particular order of preference.
     */
    public function withoutStages(): self
    {
        return $this->evolve(function (self $query): void {
            $query->stages = false;
        });
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The limit of a search must be at least 1.');
        }

        return $this->evolve(function (self $query) use ($limit): void {
            $query->limit = $limit;
        });
    }

    /**
     * The value of the definition's partition column to search in.
     */
    public function partition(string|int $value): self
    {
        return $this->evolve(function (self $query) use ($value): void {
            $query->partition = $value;
        });
    }

    /**
     * The viewer's locale, for transliteration variants of the phrase (`Müller` → `mueller` for `de`).
     */
    public function locale(?string $locale): self
    {
        return $this->evolve(function (self $query) use ($locale): void {
            $query->locale = $locale;
        });
    }

    /**
     * The best records, ranked, `limit` of them, after the scope.
     */
    public function get(): SearchResults
    {
        $this->guard();

        return $this->driver()->get($this);
    }

    /**
     * "Search for similar": names that contain the words of the phrase, on the user's request.
     */
    public function similar(): SearchResults
    {
        $this->guard();

        return $this->driver()->similar($this);
    }

    /**
     * Narrows a table query to every record that matches. Pagination, counting and sorting stay
     * with the table; the query is changed in place and returned.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $tableQuery
     * @return Builder<TModel>
     */
    public function apply(Builder $tableQuery): Builder
    {
        $this->guard();

        if (! $tableQuery->getModel() instanceof $this->modelClass) {
            throw new InvalidArgumentException(sprintf('The table query is for %s, but this search is for %s.', $tableQuery->getModel()::class, $this->modelClass));
        }

        return $this->driver()->apply($this, $tableQuery);
    }

    /**
     * Raw candidates: no scope, no authorization, no hydration. Only available after withoutScope().
     *
     * @return Collection<int, array{type: string, id: string, mode: Mode, kind: string, value: string|null, stage: string|null}>
     */
    public function candidates(): Collection
    {
        if ($this->unscopedReason === null) {
            throw new SearchScopeMissing('candidates() returns raw values without any scope; call withoutScope() with a reason first.');
        }

        $this->guard();

        return $this->driver()->candidates($this);
    }

    /**
     * @internal
     */
    public function definition(): CompiledDefinition
    {
        return resolve(ModelSearchRegistry::class)->for($this->modelClass);
    }

    /**
     * @internal
     */
    public function phraseText(): string
    {
        return $this->phrase;
    }

    /**
     * @internal
     */
    public function viewerLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public function onlyKinds(): array
    {
        return $this->kinds;
    }

    /**
     * @internal
     *
     * @return list<Mode>
     */
    public function onlyModes(): array
    {
        return $this->modes;
    }

    /**
     * @internal
     */
    public function stagesEnabled(): bool
    {
        return $this->stages;
    }

    /**
     * @internal
     */
    public function maxResults(): int
    {
        return $this->limit;
    }

    /**
     * The host scope, or, after withoutScope(), an unrestricted query of the model.
     *
     * @internal
     */
    public function scope(): HostQuery
    {
        $this->guard();

        return $this->host ?? HostQuery::from((new ($this->modelClass))->newQuery());
    }

    /**
     * The partition column and the value to search in, null when the definition is not partitioned.
     *
     * @internal
     *
     * @return array{column: string, value: string|int}|null
     */
    public function partitionFilter(): ?array
    {
        $column = $this->definition()->partition ?? config('model-search.partition_column');
        $column = is_string($column) && $column !== '' ? $column : null;

        if ($column === null) {
            if ($this->partition !== null) {
                throw new PartitionMissing(sprintf('A partition value was given, but %s has no partition column.', $this->modelClass));
            }

            return null;
        }

        if ($this->partition === null) {
            throw new PartitionMissing(sprintf('%s is partitioned by "%s"; call partition() with the value to search in.', $this->modelClass, $column));
        }

        return ['column' => $column, 'value' => $this->partition];
    }

    private function guard(): void
    {
        if (! $this->host instanceof HostQuery && $this->unscopedReason === null) {
            throw new SearchScopeMissing('A search needs within() with the host query, or withoutScope() with a reason.');
        }

        $this->partitionFilter();
    }

    private function driver(): Driver
    {
        return resolve(DriverManager::class)->for($this->definition());
    }

    /**
     * @param  Closure(self): void  $change
     */
    private function evolve(Closure $change): self
    {
        $clone = clone $this;
        $change($clone);

        return $clone;
    }
}
