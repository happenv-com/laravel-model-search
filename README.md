# laravel-model-search

Universal record search for Laravel models, backed by PostgreSQL trigram indexes (`pg_trgm`).

> Work in progress — the API is not stable yet.

## Requirements

- PHP 8.4+
- Laravel 13
- PostgreSQL 18+ with the `pg_trgm` extension

## Installation

```bash
composer require happenv-com/laravel-model-search
php artisan vendor:publish --tag=model-search-config
```

## Privacy of the search phrase

A phrase is never part of the SQL text, a log line, an event or an exception message. Every statement that carries one runs through `SearchSession` on a clone of your connection that has no event dispatcher and no query log, with the phrase sent as a bound parameter (`PDO::ATTR_EMULATE_PREPARES` off, `Pdo\Pgsql::ATTR_DISABLE_PREPARES` on, restored afterwards), inside a transaction that sets `statement_timeout`, `max_parallel_workers_per_gather = 0`, `pg_trgm.word_similarity_threshold` and `log_parameter_max_length_on_error = 0` (read back).

Before each search the privacy gate reads the server's logging settings (`log_statement`, `log_min_duration_statement`, `auto_explain.log_min_duration`, sampling and debug settings). A server that could log a phrase makes the search throw `SearchPrivacyUnavailable` (`'privacy' => 'refuse'`, the default) or run and dispatch `SearchPrivacyUnsafe` (`'privacy' => 'warn'`). The statement timeout is always kept below the lowest slow-statement threshold of the server.

Bind a `Happenv\ModelSearch\Contracts\SearchTermObserver` to receive every phrase for your own scrubbing, and listen to `SearchPerformed` (definition, duration, result count, timed out; no phrase). `rate_limit` (`['max_attempts' => 30, 'decay_seconds' => 10]`) limits searches per viewer and throws `SearchRateLimited` before any query.

## Doctor

`php artisan model-search:doctor` lists what is wrong (lag, drifted triggers or indexes, missing or invalid planned indexes, types that are not installed or ready, failed records, unknown nodes, key rows without an entry or with a stage that differs from their entry's, privacy violations) and exits with a failure when there is anything to report. `Happenv\ModelSearch\Postgres\Doctor\Doctor::inspect()` returns the same findings for your own alerting.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
