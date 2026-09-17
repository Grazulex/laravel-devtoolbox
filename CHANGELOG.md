# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
