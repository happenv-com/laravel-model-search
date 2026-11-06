# Changelog

## Unreleased (0.3.0)

- **Breaking:** a relation on a field path (or a `via` field) that carries query conditions of its own (a where, a join, a `limit`, `ofMany()`, a group, a union) is refused with an `InvalidSearchDefinition` naming the model, the relation and the path. The index was built from the relation's keys only, so those conditions were silently ignored and it held rows the relation never returns. Ordering is still accepted. Use a relation without conditions and declare the condition on the field.
- New `SearchField::where($relation, $column, $value)` restricts the rows of one relation on the path (equality, or `IN` for a list; scalars and backed enums). It is applied in the projection, the backfill and the `database` driver, its column is watched by the triggers, and it is part of the fingerprint. See "Conditions on a path" in the README.

## v0.2.1 - 2026-11-05

- `Doctor` and `Finding` are public: `Doctor::inspect()` returns a list of `Finding` (`code`, `message`, `subject`) for your own alerting. The README documents the shape.
- `model-search:sync` prints a warning and exits 0 when every refusal is a downgrade (an older release running the command during a blue/green deploy), matching the listener that runs after migrations. Any other refusal still fails the command. `--check` is unchanged.

## 0.2.1

- `Doctor` and `Finding` are public: `Doctor::inspect()` returns a list of `Finding` (`code`, `message`, `subject`) for your own alerting. The README documents the shape.
- `model-search:sync` prints a warning and exits 0 when every refusal is a downgrade (an older release running the command during a blue/green deploy), matching the listener that runs after migrations. Any other refusal still fails the command. `--check` is unchanged.
