# laravel-model-search

Fast, privacy-conscious record search for Laravel models, backed by a PostgreSQL trigram projection (`pg_trgm`).

You describe what is searchable on a model (identifiers, names, related records); the package keeps a small, derived index up to date from change-capture triggers and answers "find the five best records for this phrase" in one indexed statement, even for several models at once (a command palette), while keeping the host's authorization in charge of what a viewer may see and the search phrase out of every log.

- A search is **fail-closed**: it must say whose records it may see (`within()`), or explicitly opt out with a reason (`withoutScope()`).
- The index is a **projection**, never a source of truth. It is rebuilt from your tables whenever the definition changes.
- The phrase never reaches query listeners, logs, events or exception messages (see [Privacy](#privacy)).

## Requirements

- PHP 8.4+, Laravel 13.
- PostgreSQL 18+ with the `pg_trgm` extension (`CREATE EXTENSION pg_trgm`).
- The role that runs `php artisan model-search:sync` (and `migrate`, which runs it) must **own** the watched tables and the package tables, or be a member of their owner.
- The change-capture triggers are `SECURITY INVOKER`: every role that writes to a watched table needs `INSERT` on the package's changes table, and `UPDATE` on its state table to `TRUNCATE` a watched table. Grant these to your application's writing role.
- Run `model-search:sync` on a **direct** connection, not a transaction-pooled one (PgBouncer in transaction mode): it holds a session advisory lock for the whole run.
- Searches disable parallel query workers for their own transaction (`max_parallel_workers_per_gather = 0`), because many concurrent sessions each starting workers exhaust `/dev/shm`. Give the container a reasonably sized `/dev/shm` anyway if you run many sessions.
- Under read/write splitting a search runs on the **primary** (it shares your write connection so that it sees uncommitted rows of the current transaction).

## Installation

```bash
composer require happenv-com/laravel-model-search
php artisan vendor:publish --tag=model-search-config   # optional
php artisan migrate
```

The package's migrations are loaded from the package and are never published. `migrate` creates the tables, then installs the triggers and indexes derived from your definitions (see [Deploying](#deploying)).

Reference: [`config/model-search.php`](config/model-search.php). The values you are most likely to change are `partition_column`, `privacy`, `pool`, `search.timeout_ms`, `drain.*`, `lag_alarm_seconds`, `rate_limit` and `cache_store`.

## Defining a search

One class per searchable model. The model is named with an attribute.

```php
use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\Mode;
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
            SearchField::name('shippingAddress.name')->kind('shipping_name'),
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
}
```

Register definitions (and extensions) with the registry, for instance in a service provider:

```php
use Happenv\ModelSearch\Facades\ModelSearches;

ModelSearches::register(OrderSearch::class);
ModelSearches::extend(MarketOrderSearchExtension::class);
```

If you use `laravel-true-modular`, the `Happenv\ModelSearch\Integration\TrueModular\RegistersModelSearches` provider trait offers `hasModelSearches([...])` / `hasModelSearchExtensions([...])`; call `processModelSearches()` from the provider's `moduleInitialized()` hook, or nothing is registered. **Experimental:** this two-step registration may change before 1.0.

The facade also answers two questions about the index of a model: `ModelSearches::isReady(Order::class)` (the index has been built completely at least once, so searches are served from it) and `ModelSearches::isRebuilding(Order::class)` (a rebuild is pending: right after installing, or after a definition changed). Both are cached for a few seconds per connection and database. Under the `database` driver there is no index: `isReady()` is `true` and `isRebuilding()` `false` without touching the schema. `ModelSearches::for()` and `all()` return `CompiledDefinition`, an `@internal` type that may change in any 0.x release.

### Fields

| Field | Matched by | Notes |
|---|---|---|
| `SearchField::key($path)` | `Exact`, `Prefix`, `Suffix` (opt-in `Infix`, `database` driver only) | Identifiers: numbers, e-mails, phones, SKUs. Separators and case are folded. |
| `SearchField::name($path)` | `Prefix` on the words of the name, `Fuzzy` on demand | People, companies, products. |
| `SearchField::via($relation, $kinds = [])` | the modes of the related fields | Finds a record through a related record, one `BelongsTo` hop. |

`$path` is a column of the model or a dotted path through relations. The **kind** of a field defaults to the last segment of its path (`billingAddress.phone` is `phone`); override it with `->kind()`. `name_word` is reserved. Further modifiers:

- `matches(Mode ...)` narrows (or, for keys, adds `Infix`) the modes. **`Infix` works only in the `database` driver**: the `postgres` driver has no index for it and ignores it, so a key field declared for `Infix` alone never matches there (the doctor reports it). A name field's `Prefix` is what makes its words searchable; `Fuzzy` is read only by `similar()`.
- `label(Closure(): string)`: labels are closures, so they resolve in the viewer's locale, not at boot.
- `exactOnSource()`: also reads the model's own column for exact matches, so a record created a second ago is found by its exact number before the index has seen it. Only on own-table keys that match `Exact`.
- `normalizeUsing(Normalizer)`: your own normalization for the stored and the searched form.
- `translatable()`, `weight(int)`, `revealUsing(Closure(record, viewer): bool)`: see [Results](#results).

Definition options: `sortAt()` (tie-break column), `partition()` (column that partitions the index, falling back to `partition_column`; then every search must call `->partition($value)`), `withScopes()` (global scopes, besides soft deletes, that still apply while paths are indexed), `fallback()` and `version()`.

Paths and the stage constraint are compiled to the change-capture triggers, so they are validated when the definition is first compiled: related models must live on the same connection, `via` follows a single `BelongsTo`, and a bad definition throws an `InvalidSearchDefinition`.

### Extensions

A `ModelSearchExtension` adds fields to someone else's definition without depending on the owner class:

```php
final class MarketOrderSearchExtension extends ModelSearchExtension
{
    public function model(): string { return Order::class; }

    public function fields(): array
    {
        return [SearchField::key('marketOrder.external_number')->kind('market_number')];
    }
}
```

An extension for a model without a definition is an error (`ExtensionWithoutDefinition`).

### Stages

A stage is a narrowing of the model's own query ("open orders") that is searched first; `SearchStage::all()` is the unconstrained last one. At most one constraint stage besides `all()`. The constraint may only touch the model's own table, and every column it touches must be declared with `watching([...])`: the stage of a record is stored in the index and recomputed only when a watched column changes.

Consequences worth knowing:

- Key matches carry the stage, so the "open" pass is a single indexed read of the key rows (no join to the model's table). Name words carry it too.
- A **time-relative** constraint (`where('due_at', '<', now())`) is refused: the index would go stale without any write. Date objects are detected; a date passed as a plain string cannot be, and is equally wrong.
- `withoutStages()` on a query searches everything at once.

## Searching

```php
use Happenv\ModelSearch\Definition\Mode;

$results = OrderSearch::query($phrase)
    ->within(Order::query()->visibleTo($user))   // the host's own authorization
    ->limit(5)
    ->get();

foreach ($results as $result) {
    $result->model;            // hydrated by YOUR query, by primary key
    $result->match->kind;      // e.g. "number"
    $result->match->label;     // resolved label
    $result->match->value;     // the matched value, or null when the viewer may not see it
    $result->match->mode;      // Mode::Exact, Mode::Prefix, ...
    $result->match->stage;     // stage key, or null
    $result->match->via;       // relation, when found through a related record
    $result->match->alternatives;
}

$results->timedOut;       // the search ran out of time: an empty list is NOT "nothing found"
$results->indexBuilding;  // part of the index is not built or is being rebuilt, the answer may be partial
$results->stage;          // the stage key the results come from; null when stages did not apply
```

### Scope: fail-closed

`get()`, `apply()`, `similar()` and `candidates()` throw `SearchScopeMissing` until the query has either:

- `within(Builder $host, array $via = [])`: the host's own query of the model. Only records in that query can be returned, and the models are loaded by that query's own primary-key lookup. The host query is never mutated. Constraints that cannot be embedded in a subquery (`from`, joins that change the model, `limit`, `offset`, `having`, grouping) are refused with `UnsupportedHostQuery`.
- `withoutScope(string $reason)`: every record of the model, for work with no viewer (a console command, an import). The reason is mandatory.

**`via` fields and parent scopes.** A `via` field finds a record through a parent (the customer of an order). The parent has an authorization of its own, so a `via` branch reaches a parent only through that parent's scope, otherwise it finds nothing:

```php
OrderSearch::query($phrase)->within(
    Order::query()->visibleTo($user),
    via: [Customer::class => Customer::query()->visibleTo($user)],
);
```

At most **50 parents per `via` path** are followed. `withoutScope()` drops parent scopes, so an unscoped search does not follow `via` fields. In a [palette](#palette) the scopes of the other queries are filled in automatically (an explicit `via:` wins).

Other narrowing methods (all immutable; each returns a new query):

| Method | Meaning |
|---|---|
| `forUser(?Authenticatable)` | The viewer handed to `revealUsing()` and to the rate limiter. Defaults to `Auth::user()`, which is wrong in queues and APIs: pass it explicitly there. |
| `only(['number', 'phone'])` | Only fields of those kinds. On the name fields it is **all or none**: a strict subset of the name fields that match by prefix throws `UnsupportedQuery`, because the stored name words do not record which field they came from. |
| `using(Mode ...)` | Only those modes, where the field supports them. |
| `withoutStages()` | All stages at once. |
| `limit(int)` | Default 5. |
| `partition($value)` | Required when the definition is partitioned, an error when it is not. |
| `locale(?string)` | Adds transliteration variants of the phrase for the viewer's locale (`Müller` also searches `mueller` for `de`). |

### Results

`revealUsing(fn ($record, $viewer): bool)` on a field hides the matched **value** (the record itself is still in scope) when it returns false. A matched name word is revealed only if every name field that matches by prefix allows it. For raw `candidates()` a rule needs a model, so a value is shown only for fields without a rule.

### How a phrase is understood

- Minimum lengths: `Prefix` needs 3 characters (4 when narrowing a table with `apply()`), `Suffix` 4 in both drivers, the `database` driver's `Infix` 6.
- A phrase is normalized (case, diacritics, separators). **A phrase that normalizes to nothing runs no query and costs no rate-limit attempt.** The same holds for stored values: text that ASCII folding reduces to nothing (**CJK, emoji**, invalid UTF-8) is not indexed, so it is **not searchable**; keep such columns out of definitions or give them a `normalizeUsing()` normalizer.
- An **identifier-shaped phrase** (contains `@`, or at least `identifier_rule.digit_share` of its letters and digits are digits) does not search names.
- Name matching is by **words**: each word of a name (split on whitespace and hyphens; apostrophes dropped; `_ . / # + , : ;` separate words) is indexed, up to `name_words.max_per_record` per record, from `name_words.min_length` characters. `john smi` matches "John Smith" by the prefix of each word. Prefix matching needs at least 3 characters in the longest query word (4 when narrowing a table with `apply()`).
- `Fuzzy` (typo tolerance, `pg_trgm.word_similarity_threshold`) is never part of `get()` or the palette; it is read only by `similar()`.
- `similar()` matches **every name in the record**, including name fields that did not declare `Fuzzy` (the index does not record which field a name came from).

### `apply()`: narrow a table

```php
$query = OrderSearch::query($phrase)
    ->within(Order::query()->visibleTo($user))
    ->apply(Order::query()->latest());   // changed in place and returned
```

Every matching record, unranked; pagination, counting and sorting stay with the table. **The result is your table query ∩ the `within()` scope ∩ the matches**, on every driver and path (ready index, fallback while building, `database` driver): the scope's keys are a semi-join on your table query, run on your connection with no phrase in it. `withoutScope()` means no extra narrowing. The models you get back are yours: the narrowing adds no attribute of its own (a query without explicit columns is selected as `<table>.*`). The matching keys are resolved on the search connection and handed to your query as a bound key list, so an `orWhere` on your table query cannot widen the result. A phrase that matches too much throws `SearchTooBroad`: either more than `apply.max_ids` rows in a branch (this counts rows per branch, so the cap bounds the work), or a `via` field with more than 50 parents. Catch it and ask for a longer phrase. `apply()` never returns a silently partial list.

### `similar()`

"Search for similar", on the user's request: the records whose names contain a word similar to a word of the phrase (typo tolerant), ranked by similarity, cut after the scope.

```php
$similar = OrderSearch::query($phrase)->within($host)->similar();
```

### `candidates()`

Raw rows (`type`, `id`, `mode`, `kind`, `value`, `stage`), no hydration, no authorization. Only after `withoutScope()` (a `within()` scope applies to the `exactOnSource` branch alone). May throw `SearchTimedOut`, or `SearchIndexNotReady` (see [While an index is building](#while-an-index-is-building)).

### Palette

Several models in one search, one statement, one keystroke of one viewer:

```php
use Happenv\ModelSearch\Query\SearchQuery;

$palette = SearchQuery::palette(
    OrderSearch::query($phrase)->within(Order::query()->visibleTo($user))->forUser($user),
    CustomerSearch::query($phrase)->within(Customer::query()->visibleTo($user))->forUser($user),
);

$palette['orders'];     // SearchResults, keyed by morph alias, in the order given
```

- One query per model, and **one viewer for all queries**, or `InvalidArgumentException`.
- A `via` field whose parent model is in the palette follows that parent's scope automatically.
- The rate limit is charged once for the whole palette.
- Each type is ranked on its own: a stronger match (exact before prefix) first, with `sortAt` ordering equals.

### While an index is building

When a type has never been fully indexed, a search is answered from the source tables by the `database` driver if the definition's `fallback()` is true (the default; no typo tolerance, every search scans, so use it for small tables or short windows). With `fallback(): false`, `get()` searches only the `exactOnSource` fields meanwhile, `similar()` returns nothing, and `apply()` and `candidates()` throw `SearchIndexNotReady` (a silently partial list would pass for the answer; the message never carries the phrase). Otherwise the search never throws. Set `'driver' => 'database'` to use the source-table driver everywhere (small tables, local development).

Every result of such a search carries `indexBuilding = true`. So does a search of a **ready type whose rebuild is pending** (a changed definition, `model-search:rebuild`, a drain that switched to a rebuild): the existing index keeps serving, with no gap, but it may not contain what the rebuild has yet to write (for instance a field the new definition adds), and `indexBuilding` says so. The flag is read from a cache that lives a few seconds.

### Timeouts and rate limits

A search runs in its own transaction with `statement_timeout = search.timeout_ms` (`similar.timeout_ms` for `similar()`), never longer than the privacy gate allows. A timeout gives `timedOut = true` (`get()`, `similar()`) or `SearchTimedOut` (`apply()`, `candidates()`). `rate_limit` limits searches per viewer and throws `SearchRateLimited` before any query.

## Keeping the index fresh

The index is derived from your tables by triggers (which log what changed) and a **drain** (which recomputes affected records). The package does **not** schedule the drain; your application does, as often as its freshness target needs:

```php
// routes/console.php
Schedule::job(new DrainSearchIndex)->everyMinute();
// or: Schedule::command('model-search:drain')->everyMinute();
```

Overlapping drains are harmless (the loser stops on an advisory lock). `drain.budget_ms` caps one run; `--budget=` overrides it.

- **Lag:** when the oldest unprocessed change has waited longer than `lag_alarm_seconds`, `SearchIndexLagging` is dispatched (and `model-search:doctor` reports it).
- **Poison records:** a record whose projection cannot be computed even alone is parked in a failures table (only the exception class is kept, never its message, which may quote data). It is retried automatically after 24 hours, up to 5 times. After fixing the cause: `php artisan model-search:retry-failures [--subject=orders] [--id=42]`.
- `php artisan model-search:rebuild [--subject=...]` marks a type for a full rebuild from the source tables; the drain performs it.
- `SearchIndexChanged` is dispatched after a batch changed a root type (no record, no phrase).

### In tests

```php
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;

resolve(SearchIndexer::class)->drainNow();   // everything pending, no time budget
```

The triggers fill the change log inside your test's writes; `drainNow()` brings the projection up to date before you search. A drain refuses to start inside an open transaction (`DrainInsideTransaction`) because its advisory lock and events would leak into the caller's transaction. If your test suite wraps every test in one transaction (`RefreshDatabase`), set the level the harness holds:

```php
config()->set('model-search.drain.allowed_transaction_level', 1);
```

## Deploying

The package keeps the triggers and indexes in step with your definitions and is built for blue/green deploys, where the previous release keeps serving traffic against the same database.

- `migrate` ends by running `model-search:sync`, which **creates or replaces** only. It never drops anything, because the previous release may still need an object the new plan no longer has.
- If the sync cannot finish (a table stayed locked, an index build kept failing, the extension is missing, the role does not own the tables), the migrations have already committed, so **`migrate` exits non-zero by design**. Run `php artisan model-search:sync` to finish.
- Objects that no registered definition needs any more are reported and removed only by `php artisan model-search:sync --prune`. Run it as a contract step, once the previous release is gone.
- `model-search:sync --check` changes nothing and exits non-zero when there is drift (missing, changed, disabled or invalid objects, uninstalled or mismatched types). Objects pending a `--prune` are listed but do not fail it. `--dry-run` prints the DDL.
- Indexes are built `CONCURRENTLY`; triggers are replaced one table at a time with a short lock timeout and retried, so a long writer is not queued behind. Run the sync on a direct connection (see [Requirements](#requirements)).

### Versions and fingerprints

Every definition has a fingerprint (its fields, paths, stage constraint and name-word settings) and `ModelSearch::version()`.

- A release applies and rebuilds **only the types whose definition it owns**: a type installed by a newer version, or by another release with a different fingerprint at the same version, is left alone. The older release, running `migrate` during a deploy, logs a warning instead of failing the deploy.
- **Every change to a definition requires a higher `version()`**: its fields (including the order they are declared in: name words are capped per record in that order), modes, stages, normalizers or relations, and also `name_words.*`, `partition_column` and `sortAt()`. Without the bump `migrate` fails with `InstallRefused` ("different fingerprint"), the doctor reports `fingerprint-differs`, and the type keeps serving from its old projection.
- Change a definition's **behaviour** in a way the fingerprint cannot see (a custom normalizer, a `revealUsing` that changes what is indexed) and you must **bump `version()`** to force a rebuild.
- **Rolling back to an older release requires a version bump too:** a revert would otherwise present an older version than the one installed and be refused. Bump it above the one you are reverting from.
- A changed definition rebuilds the type in the background from its source tables. Searches keep using the existing index meanwhile and report `indexBuilding`, as described in [While an index is building](#while-an-index-is-building).

### Several databases

Sync, drain and doctor run on the **default connection**. A host with several databases (a database per tenant, say) runs them once per database, for instance by wrapping the commands in its tenant-aware runner; `SchemaSync` skips the definitions whose tables live on another connection.

## Public API

Everything marked `@internal` (the compiler, the SQL builders, the drivers, the schema and indexing helpers, the normalization helpers) may change in any 0.x release. That includes `DriverManager::extend()` and the `Driver` interface: a custom driver needs internal accessors of `SearchQuery`. Public: the definition classes, the registry and its facade, `SearchQuery`, the results, the exceptions, the events, the contracts, the commands, the jobs, `Normalizer`, `SearchIndexer`, `IndexState` and `DrainStats`. Every exception implements `Happenv\ModelSearch\Exceptions\ModelSearchException`; `UnsupportedQuery` (a query nothing can answer) is a runtime refusal, not a definition error.

## Privacy

A search phrase is personal data: it is typed by a person and may be a name, an e-mail or a phone number. The package keeps it out of everything you do not control.

- **Never in SQL text:** every statement carrying a phrase (or a variant, a candidate, an `apply()` or a `similar()`) runs through `SearchSession` on a clone of your connection with no event dispatcher and no query log, with the phrase as a bound parameter (emulated prepares off, `ATTR_DISABLE_PREPARES` on, restored afterwards). Your `DB::listen`, Telescope, Debugbar and slow-query logs never see it.
- **Never in errors:** a failure is classified by SQLSTATE and rethrown without the SQL, the bindings or a `previous`; `SearchTimedOut`, `SearchFailed`, `SearchTooBroad` and the like never carry the phrase. Each search transaction sets `log_parameter_max_length_on_error = 0` and reads it back, so a server error log shows `$1`, not the phrase.
- **Never in events:** `SearchPerformed` carries the definition, duration, result count and `timedOut`, and nothing else.
- **The privacy gate** reads the server's logging settings before each search (cached for a minute): `log_statement = all`, `log_min_duration_statement`, `log_min_duration_sample` with sampling, `log_transaction_sample_rate`, `auto_explain.log_min_duration`, `debug_print_*`. If the server could write a phrase to its logs, `'privacy' => 'refuse'` (the default) throws `SearchPrivacyUnavailable` (say "search is temporarily unavailable" in your UI); `'warn'` runs the search and dispatches `SearchPrivacyUnsafe`. The statement timeout is always kept below the lowest slow-statement threshold, so a "slow statement" log cannot fire for a search.
- Bind a `Happenv\ModelSearch\Contracts\SearchTermObserver` to receive every phrase for your own scrubbing mechanism (an error reporter's data scrubber, say); it is the only place a phrase leaves the package, and it is called before every search, so keep it cheap.

## Doctor

`php artisan model-search:doctor` lists what is wrong and exits with a failure when there is anything to report: lag, drifted triggers or indexes, missing or invalid planned indexes, types that are not installed, not ready or installed for another definition, a key field declared for `Infix` alone under the `postgres` driver, failed records, change-log nodes no definition watches, key rows without an entry or with a stage that differs from their entry's, and privacy violations (including a server where `log_parameter_max_length_on_error` cannot be set to 0). `Happenv\ModelSearch\Postgres\Doctor\Doctor::inspect()` returns the same findings for your own alerting. Its key checks are bounded by `doctor.timeout_ms`.

## Known limits

- **Float columns:** triggers compare watched columns by their text output, and the text of `float4`/`float8` depends on the writing session's `extra_float_digits`. With a value of 0 or lower, a change that only shows in the digits that are not printed goes unlogged. Watch a float column only if that cannot matter, or keep `extra_float_digits` at 1 or higher.
- **Time-relative stages** are refused (see [Stages](#stages)); a date passed as a string cannot be detected.
- `similar()` matches every name in the record, not just the fields that declared `Fuzzy`.
- `only()` with a strict subset of the name fields is not supported.
- Full-text matching on long text (`SearchField::text()`) is not implemented.
- One `BelongsTo` hop for `via`, at most 50 parents per path: `apply()` throws `SearchTooBroad` beyond that, `get()` follows the first 50.
- The `database` driver has no typo tolerance and scans the source tables.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
