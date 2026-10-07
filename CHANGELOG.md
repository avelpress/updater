# Release Notes

## [1.0.0] - 2026-10-07

### Added

- Plugin updater moved out of `avelpress/avelpress` (1.3.2) into its own package,
  keeping the `AvelPress\Update` namespace, the classes and the config format
- `UpdaterBootstrapper::boot()`, which registers the updater from the `updater`
  key of `AvelPress::init()` (AvelPress 2.0 or later)
