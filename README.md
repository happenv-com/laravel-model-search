# laravel-model-search

Universal record search for Laravel models, backed by PostgreSQL trigram indexes (`pg_trgm`).

> Work in progress — the API is not stable yet.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- PostgreSQL with the `pg_trgm` extension

## Installation

```bash
composer require happenv-com/laravel-model-search
php artisan vendor:publish --tag=model-search-config
```

## Licence

MIT. See [LICENSE.md](LICENSE.md).
