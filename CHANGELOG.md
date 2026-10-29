# Changelog

## [Unreleased]

### Fixed

- `editions` is read as a boolean whatever its spelling (omnisense/omnischolar's `Config::bool()`):
  PHP's `(bool)` read the string `'false'` as true, one record per edition where one per work was
  asked. A value that is no boolean is an `InvalidConfigException`.
