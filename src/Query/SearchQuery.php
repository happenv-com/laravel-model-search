<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Query;

use Closure;
use Happenv\ModelSearch\Contracts\SearchTermObserver;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Drivers\Driver;
use Happenv\ModelSearch\Drivers\DriverManager;
use Happenv\ModelSearch\Events\SearchPerformed;
use Happenv\ModelSearch\Exceptions\PartitionMissing;
use Happenv\ModelSearch\Exceptions\SearchRateLimited;
use Happenv\ModelSearch\Exceptions\SearchScopeMissing;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Happenv\ModelSearch\Exceptions\UnsupportedHostQuery;
use Happenv\ModelSearch\RateLimiting\SearchRateLimit;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
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

    /** @var array<class-string<Model>, HostQuery> */
    private array $parents = [];

    private ?Authenticatable $viewer = null;

    private bool $viewerGiven = false;

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
     * A `via` field reaches a parent record only through the parent's own scope, given here by the
     * parent model class. Without one, that `via` field finds nothing.
     *
     * @param  Builder<Model>  $host
     * @param  array<class-string<Model>, Builder<Model>>  $via
     */
    public function within(Builder $host, array $via = []): self
    {
        if (! $host->getModel() instanceof $this->modelClass) {
            throw new UnsupportedHostQuery(sprintf('The host query is for %s, but this search is for %s.', $host->getModel()::class, $this->modelClass));
        }

        $scope = HostQuery::from($host);
        $parents = [];

        foreach ($via as $parentClass => $parentHost) {
            if (! is_subclass_of($parentClass, Model::class) || ! $parentHost->getModel() instanceof $parentClass) {
                throw new UnsupportedHostQuery('A parent scope must be a query of the model it is registered for.');
            }

            $parents[$parentClass] = HostQuery::from($parentHost);
        }

        return $this->evolve(function (self $query) use ($scope, $parents): void {
            $query->host = $scope;
            $query->parents = $parents;
            $query->unscopedReason = null;
        });
    }

    /**
     * The viewer that `revealUsing()` rules see. Without this call it is the authenticated user.
     */
    public function forUser(?Authenticatable $viewer): self
    {
        return $this->evolve(function (self $query) use ($viewer): void {
            $query->viewer = $viewer;
            $query->viewerGiven = true;
        });
    }

    /**
     * Searches every record of the model, for work that has no viewer. The reason is the audit trail.
     *
     * Parent scopes given to an earlier `within()` are dropped: a `via` field never follows a parent
     * without a scope of its own, so an unscoped search does not follow `via` fields at all.
     */
    public function withoutScope(string $reason): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Searching without a scope needs a reason.');
        }

        return $this->evolve(function (self $query) use ($reason): void {
            $query->host = null;
            $query->parents = [];
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
        $this->admit();

        return $this->performed(fn (): SearchResults => $this->driver()->get($this), static fn (SearchResults $results): int => count($results), static fn (SearchResults $results): bool => $results->timedOut);
    }

    /**
     * "Search for similar": names that contain the words of the phrase, on the user's request.
     */
    public function similar(): SearchResults
    {
        $this->guard();
        $this->admit();

        return $this->performed(fn (): SearchResults => $this->driver()->similar($this), static fn (SearchResults $results): int => count($results), static fn (SearchResults $results): bool => $results->timedOut);
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

        $this->admit();

        return $this->driver()->apply($this, $tableQuery);
    }

    /**
     * Raw candidates: no scope, no authorization, no hydration. Only available after withoutScope().
     *
     *
     * @return Collection<int, array{type: string, id: string, mode: Mode, kind: string, value: string|null, stage: string|null}>
     *
     * @throws SearchTimedOut
     */
    public function candidates(): Collection
    {
        if ($this->unscopedReason === null) {
            throw new SearchScopeMissing('candidates() returns raw values without any scope; call withoutScope() with a reason first.');
        }

        $this->guard();
        $this->admit();

        return $this->performed(fn (): Collection => $this->driver()->candidates($this), static fn (Collection $candidates): int => $candidates->count(), static fn (): bool => false);
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
     * The scope of a parent model for `via` fields, matched by class or by a base class it was given
     * for; null keeps those fields closed.
     *
     * @internal
     *
     * @param  class-string<Model>  $parentClass
     */
    public function parentScope(string $parentClass): ?HostQuery
    {
        if (isset($this->parents[$parentClass])) {
            return $this->parents[$parentClass];
        }

        foreach ($this->parents as $scopedClass => $scope) {
            if (is_a($parentClass, $scopedClass, true)) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * @internal
     */
    public function viewer(): ?Authenticatable
    {
        if ($this->viewerGiven) {
            return $this->viewer;
        }

        $user = Auth::user();

        return $user instanceof Authenticatable ? $user : null;
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

    /**
     * Charges the viewer's rate limit, which stops a search before any query, and hands the phrase to
     * the host's observer.
     *
     * @throws SearchRateLimited
     */
    private function admit(): void
    {
        resolve(SearchRateLimit::class)->attempt($this->viewer());
        resolve(SearchTermObserver::class)->observe($this->phrase);
    }

    /**
     * Runs a search and reports it with {@see SearchPerformed}: no phrase, no value, no record.
     *
     * @template TOutcome
     *
     * @param  Closure(): TOutcome  $search
     * @param  Closure(TOutcome): int  $count
     * @param  Closure(TOutcome): bool  $timedOut
     * @return TOutcome
     */
    private function performed(Closure $search, Closure $count, Closure $timedOut): mixed
    {
        $started = hrtime(true);

        try {
            $outcome = $search();
        } catch (SearchTimedOut $exception) {
            $this->announce($started, 0, true);

            throw $exception;
        }

        $this->announce($started, $count($outcome), $timedOut($outcome));

        return $outcome;
    }

    private function announce(int|float $started, int $count, bool $timedOut): void
    {
        Event::dispatch(new SearchPerformed($this->definition()->morphAlias, (int) round((hrtime(true) - $started) / 1_000_000), $count, $timedOut));
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
