# Changelog

## v0.5.2 - 2026-11-11

### What's Changed

* chore: keep dev-only files out of the Composer dist archive by @webard in https://github.com/happenv-com/laravel-model-search/pull/10

**Full Changelog**: https://github.com/happenv-com/laravel-model-search/compare/v0.5.1...v0.5.2

## v0.5.1 - 2026-11-11

- `migrate` installs the triggers and types again when Laravel's migrator runs on the direct variant of a pooled connection (`pgsql::direct`). Connections are compared by their base name (`Sql::baseConnectionName()` strips `::read`, `::write` and `::direct`), so models that name the base connection are no longer taken for another database's. Found on the first production deploy behind PgBouncer: tables and indexes were created, triggers and installs were not, and nothing was reported. (#8)
- The package ships a Laravel Boost skill, `laravel-model-search-development` (`resources/boost/skills/`), offered by `php artisan boost:install` / `boost:update`. (#9)

## v0.5.0 - 2026-11-10

- `model-search.node_key_type` (`text` default, `uuid`, `bigint`) sets the column type of the change log's `node_id`. The triggers, the requeue of failures and the spill of roots write node ids in that type. The rendered trigger SQL is unchanged in text mode, so existing installs see no trigger drift.
- A typed node key must equal `subject_key_type` (roots are written back to the log as nodes), and a definition that watches a key column of another type is refused when it is compiled.
- The doctor reports `node-key-type` when the `node_id` column has another type than the setting.
- `model-search:uninstall` removes the package's trigger functions and triggers, for the `down()` of an application's own copy of the migration.

## v0.4.0 - 2026-11-10

- `model-search.subject_key_type` sets the column type of `subject_id`: `bigint` (default, like Laravel's `id()`), `uuid` (`HasUuids`) or `text` (any key, also mixed key types). Every statement binds, casts and orders subject ids in that type; the change log's `node_id` stays `text`.
- A definition whose model key does not fit the setting is refused when it is compiled (`InvalidSearchDefinition`).
- The doctor reports `subject-key-type` when a `subject_id` column has another type than the setting.
- `model-search.run_migrations` (default `true`) lets an application ship its own copy of the migration; the migration is publishable with `--tag=model-search-migrations`.
- Moment columns stay `timestamptz`: the database writes them itself and compares them with its own clock. The SQL also runs on `timestamp` columns of an application's own migration when all sessions share one `TimeZone`.
- **Breaking:** the default `subject_id` type is now `bigint` (was always `text`). Set `subject_key_type` to `text` to keep the previous column for mixed key types. Existing tables are not altered.

## v0.3.1 - 2026-11-07

- `SearchQuery::within()` accepts the builder of a concrete model (`Order::query()`, a custom builder class): the host and via builders are typed `Builder<covariant Model>`. Static analysis only; nothing changes at runtime.

## v0.3.0 - 2026-11-06

- **Breaking:** a relation on a field path (or a `via` field) that carries query conditions of its own (a where, a join, a `limit`, `ofMany()`, a group, a union) is refused with an `InvalidSearchDefinition` naming the model, the relation and the path. The index was built from the relation's keys only, so those conditions were silently ignored and it held rows the relation never returns. Ordering is still accepted. Conditions held by the relation's Eloquent builder (added or removed scopes, `afterQuery()`) are refused too, and conditions on a pivot table are not supported. Use a relation without conditions and declare the condition on the field.
- New `SearchField::where($relation, $column, $value)` restricts the rows of one relation on the path (equality, or `IN` for a list; scalars and backed enums). It is applied in the projection, the backfill and the `database` driver, its column is watched by the triggers, and it is part of the fingerprint, and its value is checked against the column's type (domains are followed to their base type) and converted by PostgreSQL once at compile time. Built-in types are compared typed, other types as text; a column without btree equality, a `money` column and a `timestamptz` value without an offset are refused. See "Conditions on a path" in the README.

## Unreleased (0.3.0)

- **Breaking:** a relation on a field path (or a `via` field) that carries query conditions of its own (a where, a join, a `limit`, `ofMany()`, a group, a union) is refused with an `InvalidSearchDefinition` naming the model, the relation and the path. The index was built from the relation's keys only, so those conditions were silently ignored and it held rows the relation never returns. Ordering is still accepted. Conditions held by the relation's Eloquent builder (added or removed scopes, `afterQuery()`) are refused too, and conditions on a pivot table are not supported. Use a relation without conditions and declare the condition on the field.
- New `SearchField::where($relation, $column, $value)` restricts the rows of one relation on the path (equality, or `IN` for a list; scalars and backed enums). It is applied in the projection, the backfill and the `database` driver, its column is watched by the triggers, and it is part of the fingerprint, and its value is checked against the column's type (domains are followed to their base type) and converted by PostgreSQL once at compile time. Built-in types are compared typed, other types as text; a column without btree equality, a `money` column and a `timestamptz` value without an offset are refused. See "Conditions on a path" in the README.

## v0.2.1 - 2026-11-05

- `Doctor` and `Finding` are public: `Doctor::inspect()` returns a list of `Finding` (`code`, `message`, `subject`) for your own alerting. The README documents the shape.
- `model-search:sync` prints a warning and exits 0 when every refusal is a downgrade (an older release running the command during a blue/green deploy), matching the listener that runs after migrations. Any other refusal still fails the command. `--check` is unchanged.

## 0.2.1

- `Doctor` and `Finding` are public: `Doctor::inspect()` returns a list of `Finding` (`code`, `message`, `subject`) for your own alerting. The README documents the shape.
- `model-search:sync` prints a warning and exits 0 when every refusal is a downgrade (an older release running the command during a blue/green deploy), matching the listener that runs after migrations. Any other refusal still fails the command. `--check` is unchanged.
