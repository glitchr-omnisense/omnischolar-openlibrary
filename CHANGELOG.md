# Changelog

## [Unreleased]

### Added

- `policy.json`: the service's usage policy as data (limits, licence, credit, what a request needs), read at its pages on 2026-11-10 and named in `composer.json` (`extra.omnisense.policy`); the core honours it on every request.

### Fixed

- `editions` is read as a boolean whatever its spelling (omnisense/omnischolar's `Config::bool()`):
  PHP's `(bool)` read the string `'false'` as true, one record per edition where one per work was
  asked. A value that is no boolean is an `InvalidConfigException`.
