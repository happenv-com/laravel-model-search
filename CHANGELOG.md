# Changelog

## 0.2.1

- `Doctor` and `Finding` are public: `Doctor::inspect()` returns a list of `Finding` (`code`, `message`, `subject`) for your own alerting. The README documents the shape.
- `model-search:sync` prints a warning and exits 0 when every refusal is a downgrade (an older release running the command during a blue/green deploy), matching the listener that runs after migrations. Any other refusal still fails the command. `--check` is unchanged.
