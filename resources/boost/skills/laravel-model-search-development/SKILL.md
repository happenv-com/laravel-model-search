---
name: laravel-model-search-development
description: Define, query, index and deploy record searches with happenv-com/laravel-model-search (PostgreSQL pg_trgm projection). Use when writing or changing a ModelSearch definition or extension (SearchField::key/name/via, stages, where() conditions, each(), version()), when searching (query(), within(), withoutScope(), SearchQuery::palette(), apply(), similar()), when handling timedOut / indexBuilding / SearchTooBroad / SearchRateLimited, when scheduling the drain, testing with drainNow(), or deploying (model-search:sync, --prune, --check, doctor).
---

# Laravel Model Search

Record search for Eloquent models, answered from a small index that PostgreSQL triggers and a drain keep up to date. The index is a **projection**: it is rebuilt from your tables whenever a definition changes. The host's authorization decides what a viewer may see, and the phrase never reaches logs, events or exception messages.

## When to use this skill

- Adding a model to the search, or changing what is searchable on it.
- Writing code that searches (a controller, a Livewire component, an AI tool, a command).
- Writing tests that search, or a scheduler entry, deployment step or alert for the index.

## Defining a search

One class per searchable model, named with `#[SearchesModel]`, registered in a service provider.

```php
use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Definition\SearchStage;
use Illuminate\Database\Eloquent\Builder;

#[SearchesModel(Order::class)]
final class OrderSearch extends ModelSearch
{
    public function fields(): array
    {
        return [
            SearchField::key('number')->exactOnSource()->label(fn (): string => __('Number')),
            SearchField::key('billingAddress.phone')->kind('phone'),
            SearchField::name('customer_name'),
            SearchField::via('customer', ['email']),
        ];
    }

    public function stages(): array
    {
        return [
            SearchStage::make('open', fn (Builder $query) => $query->whereIn('state', ['new', 'paid']))
                ->watching(['state'])
                ->label(fn (): string => __('Open orders')),
            SearchStage::all(),
        ];
    }

    public function sortAt(): ?string
    {
        return 'purchased_at';
    }

    public function version(): int
    {
        return 1;
    }
}
```

```php
use Happenv\ModelSearch\Facades\ModelSearches;

ModelSearches::register(OrderSearch::class);
ModelSearches::extend(MarketOrderSearchExtension::class); // a ModelSearchExtension adds fields to someone else's definition
```

### Fields

- `SearchField::key($path)`: identifiers (numbers, e-mails, phones, SKUs). Matched `Exact`, `Prefix`, `Suffix`; separators and case are folded.
- `SearchField::name($path)`: names of people, companies and products, matched by word prefix; typo tolerance only through `similar()`.
- `SearchField::via($relation, $kinds)`: finds a record through one `BelongsTo` parent, by the parent's fields of those kinds. An empty `$kinds` means every kind of the parent, e-mails and phones included, so list them.
- `$path` is a column or a dotted path through relations. The kind defaults to the last segment; override it with `->kind()`.
- Modifiers: `label(Closure)` (always a closure, so it resolves in the viewer's locale), `exactOnSource()`, `each()` (the column is a list; every element is a key), `where($relation, $column, $value)`, `weight()`, `translatable()`, `revealUsing(fn ($record, $viewer): bool)`, `normalizeUsing(Normalizer)`.

### Rules the compiler enforces

- **A relation on a path must not carry query conditions** (`where`, scopes, joins, `limit`, `ofMany()`, `withTrashed()`). It throws `InvalidSearchDefinition`. Keep the relation plain and declare the condition on the field:

```php
SearchField::key('documents.number')
    ->where('documents', 'type', 'order')
    ->where('documents', 'status', [DocumentStatus::Created, DocumentStatus::Sent]),
```

- A stage constraint touches only the model's own table, every column it reads is listed in `watching([...])`, and it is never time-relative (`now()`). At most one stage besides `SearchStage::all()`.
- `via` follows a single `BelongsTo`; `MorphTo` is refused. Relations added with `resolveRelationUsing()` work if they are registered before the definition is first compiled (in `boot()`).
- The model key must fit `model-search.subject_key_type` (`bigint`, `uuid` or `text`).

### Every change to a definition needs a higher `version()`

Fields, their order, modes, stages, normalizers, relations, `sortAt()`, conditions: anything. Without the bump `migrate` fails with `InstallRefused` ("different fingerprint") and the type keeps serving its old projection. A change the fingerprint cannot see (a custom normalizer's logic, a `revealUsing` that changes what is indexed) needs a bump too, and so does a rollback.

## Searching

A search is **fail-closed**: `get()`, `apply()`, `similar()` and `candidates()` throw `SearchScopeMissing` until the query says whose records it may see.

```php
$results = OrderSearch::query($phrase)
    ->within(Order::query()->visibleTo($user))     // the host's own authorization
    ->forUser($user)                               // explicit in queues, APIs and commands
    ->locale(app()->getLocale())
    ->limit(5)
    ->get();

foreach ($results as $result) {
    $result->model;          // loaded by YOUR query
    $result->match->kind;
    $result->match->value;   // null when revealUsing() hides it from this viewer
    $result->match->mode;
}

$results->timedOut;        // an empty list is NOT "nothing found"
$results->indexBuilding;   // the answer may be partial
```

- `within($host, via: [Customer::class => Customer::query()->visibleTo($user)])`: a `via` field reaches a parent only through that parent's scope, otherwise it finds nothing.
- `withoutScope('reason')` only for work with no viewer (a console command, an import). It does not follow `via` fields.
- Several models at once, one statement and one rate-limit charge: `Happenv\ModelSearch\Query\SearchQuery::palette(OrderSearch::query($phrase)->within(...), CustomerSearch::query($phrase)->within(...))`, keyed by morph alias. One viewer for all queries.
- Narrow a table: `OrderSearch::query($phrase)->within($scope)->apply(Order::query()->latest())`. Unranked, pagination stays yours. Catch `SearchTooBroad` and ask for a longer phrase.
- Typos: `similar()`, only when the user asks for it, never as an automatic fallback.
- Other narrowing: `only([...kinds])`, `using(Mode ...)`, `withoutStages()`, `partition($value)` (required on a partitioned definition).

Handle the states, do not show them as "no results": `timedOut`, `indexBuilding`, and the exceptions `SearchTimedOut`, `SearchTooBroad`, `SearchRateLimited`, `SearchIndexNotReady`, `SearchPrivacyUnavailable` (all implement `ModelSearchException`).

Phrase rules worth knowing: `Prefix` needs 3 characters (4 for `apply()`), `Suffix` 4. A phrase shaped like an identifier (an `@`, or mostly digits) does not search names. Text that folds to nothing (CJK, emoji) is not indexed.

## Keeping the index fresh

The package does not schedule the drain; the application does:

```php
use Happenv\ModelSearch\Jobs\DrainSearchIndex;

Schedule::job(new DrainSearchIndex)->everyMinute();
```

`model-search:rebuild [--subject=...]` marks a type for a full rebuild, `model-search:retry-failures` retries parked records, `SearchIndexLagging` fires when the oldest change waits longer than `lag_alarm_seconds`.

## Testing

The triggers log your test's writes; bring the projection up to date before you search:

```php
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;

resolve(SearchIndexer::class)->drainNow();
```

A drain refuses to run inside an open transaction (`DrainInsideTransaction`). With `RefreshDatabase`, set `config()->set('model-search.drain.allowed_transaction_level', 1);`. Tests need PostgreSQL 18+ with `pg_trgm`, or `'driver' => 'database'`.

## Deploying

- `migrate` ends with `model-search:sync`, which only creates or replaces (blue/green safe). If it cannot finish, `migrate` exits non-zero by design: run `php artisan model-search:sync`.
- `model-search:sync --prune` removes what no definition needs any more. Run it only as a contract step, after the previous release is gone.
- `model-search:sync --check` reports drift without changing anything; `--dry-run` prints the DDL.
- Run the sync on a direct connection, not through a transaction-pooling PgBouncer.
- `php artisan model-search:doctor` (or `Doctor::inspect()`, a list of `Finding` with a stable `code`) for alerting.

## Privacy

- Never log, dispatch or put the phrase in an exception message yourself. The package runs phrase-carrying SQL on a connection clone without listeners.
- To scrub phrases from your own error reporter, bind `Happenv\ModelSearch\Contracts\SearchTermObserver`.
- `'privacy' => 'refuse'` (default) throws `SearchPrivacyUnavailable` when the server could log statements: tell the user "search is temporarily unavailable".

## Do not

- Do not search without `within()` / `withoutScope()`, and do not use `withoutScope()` for a request with a viewer.
- Do not put conditions on a relation used in a path; use `where()` on the field.
- Do not change a definition without raising `version()`.
- Do not treat an empty `timedOut` or `indexBuilding` answer as "nothing found".
- Do not rely on `@internal` classes (compiler, SQL builders, drivers, `CompiledDefinition`); they change in any 0.x release.
