# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [v1.7.0] - 2026-09-17

### Added
- MCP server (`php artisan mcp:start devtoolbox`) exposing every scanner as a tool when `laravel/mcp` is installed. Registered only in `local`/`testing` (configurable via `devtoolbox.mcp`).
- `AbstractScanner::getOptionSchema()`: typed option declarations, used to build MCP input schemas; `getAvailableOptions()` is now derived from it.
- Laravel Boost guidelines (`resources/boost/guidelines/core.blade.php`) and the `devtoolbox-analysis` skill.
- MCP status in `dev:about+`.

### Changed
- `db-column-usage` now honours `unused_only` (only unused columns are returned, tables without any are omitted; the summary still covers every column) and `routes` now honours `filter_methods` (case-insensitive). Both options were declared but had no effect.
- The `method` option of `sql-trace` and `sql-analysis` accepts lower-case HTTP verbs.
- MCP responses over `devtoolbox.mcp.max_response_bytes` are now truncated inside the scanner envelope (`data.routes`, `data.column_usage`, ...) instead of collapsing `metadata`/`data` to null; the `_truncated` note reports the sliced `path`.

### Removed
- The `include_migrations`, `check_fillable` (`db-column-usage`), `show_parameters` (`container-bindings`) and `group_by_type` (`middleware`) scanner options, which were never read. CLI flags are unchanged.
- Applied Rector refactors across `src/` (strict `in_array` checks for nullable strings, closure parameter types, removal of a redundant null argument). No behavioral change.
- Rector rules that would add parameter types to closures guarding external data are now skipped in `rector.php`.
- Bumped `rector/rector` to `^2.1` (2.0.0 is incompatible with recent `nikic/php-parser`).

## [v1.6.0] - 2026-09-17

### Added
- Laravel 13 support (`illuminate/support: ^12.0|^13.0`, tested against Laravel 13.32).
- CI matrix now covers PHP 8.3 / 8.4 with Laravel 12 (testbench 10) and Laravel 13 (testbench 11), `prefer-lowest` and `prefer-stable`.

### Changed
- Minimum PHP version is now 8.3 (explicitly enforced across the whole toolchain).
- Development dependencies updated: Pest `^3.8|^4.0`, Pest Laravel plugin `^3.2|^4.0`, Orchestra Testbench `^10.0|^11.0`, Larastan `^3.4`, Rector `^2.0`, Pint `^1.22`.
- GitHub Actions bumped to `actions/checkout@v5` and `softprops/action-gh-release@v2` (Node 24 runners).
- Manual release workflow now runs the test suite against Laravel 13.

### Fixed
- CI "Tests" workflow failing on Laravel 13 (`pestphp/pest-plugin-laravel ^3.2` could not be resolved against `laravel/framework 13.*`).
- `RouteScanner` no longer reports the framework's own `storage.{disk}` / `storage.{disk}.upload` routes (registered by Laravel when a local disk has `serve => true`) as unused or unprotected routes.
- `rector.php` updated for Rector 2.6 (`strictBooleans` prepared set has been removed upstream).

### Removed
- Laravel 11 support (end of life). Use v1.5.x if you still run Laravel 11.

[Unreleased]: https://github.com/Grazulex/laravel-devtoolbox/compare/v1.6.0...HEAD
[v1.6.0]: https://github.com/Grazulex/laravel-devtoolbox/compare/v1.5.0...v1.6.0
