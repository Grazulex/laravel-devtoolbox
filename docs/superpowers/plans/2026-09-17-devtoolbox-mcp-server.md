# DevToolbox MCP Server Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose every DevToolbox scanner as an MCP tool (via `laravel/mcp`), guarded to local/testing environments, with typed argument schemas, plus Laravel Boost guidelines and a skill.

**Architecture:** A `ScannerTool` (one instance per scanner, built by `ToolFactory` from the `ScannerRegistry`) adapts `ScannerInterface::scan()` to `Laravel\Mcp\Server\Tool`. Argument schemas come from a new `AbstractScanner::getOptionSchema()` that every bundled scanner declares explicitly (with a text-based inference fallback for third-party scanners). `DevToolboxServer` is registered with `Mcp::local('devtoolbox', …)` only when `laravel/mcp` is installed and the environment is allowed.

**Tech Stack:** PHP 8.3, Laravel 12/13, `laravel/mcp ^1.0` (suggest + require-dev), `illuminate/json-schema`, Pest 3/4, PHPStan (larastan), Pint.

**Spec:** `docs/superpowers/specs/2026-09-17-devtoolbox-mcp-design.md`

## Global Constraints

- Runtime dependency constraints must stay `illuminate/support ^12.0|^13.0`, `php ^8.3`. `laravel/mcp` goes in `suggest` and `require-dev` only, never `require`.
- `ScannerInterface` (`src/Contracts/ScannerInterface.php`) must not change.
- No class from `src/Mcp/` other than `ResponseTruncator` may be referenced from code that runs without `laravel/mcp` installed (service provider references `DevToolboxServer::class` only inside a `class_exists(\Laravel\Mcp\Facades\Mcp::class)` guard).
- Tool names are `devtoolbox-<scanner name>` (e.g. `devtoolbox-routes`).
- Active (non read-only) scanners are exactly: `sql-trace`, `sql-analysis`, `performance`.
- Default config: `devtoolbox.mcp.enabled = true`, `devtoolbox.mcp.environments = ['local', 'testing']`, `devtoolbox.mcp.max_response_bytes = 262144`.
- All tests under `tests/Unit/Mcp/` and `tests/Feature/Mcp/` carry the Pest group `mcp`.
- Every code step must keep `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and `vendor/bin/pest` green. Run them before each commit.
- Commit messages: sober, English, conventional prefix (`feat:`, `test:`, `docs:`, `chore:`). No AI attribution of any kind in commits, PR, changelog or docs.
- Work on branch `feature/mcp-server` (already exists, contains the spec). Never touch tags.
- Temporary files, if any, go in `/tmp/devtoolbox-*` (the machine's `/tmp` is shared with other agents).
- Existing tests: 125 (`vendor/bin/pest` at start). The count must never decrease.

---

## File map

| File | Responsibility |
|---|---|
| `composer.json` | `laravel/mcp` in `suggest` + `require-dev`, keywords |
| `config/devtoolbox.php` | new `mcp` key |
| `src/Scanners/OptionSchemaInferrer.php` | text descriptions → typed option schema (fallback for third-party scanners) |
| `src/Scanners/AbstractScanner.php` | `getOptionSchema()` (default: inference), `getAvailableOptions()` (derived) |
| `src/Scanners/*Scanner.php` (16) | explicit `getOptionSchema()` replacing `getAvailableOptions()` |
| `src/Mcp/OptionSchemaCompiler.php` | option schema → `Illuminate\JsonSchema` types and Laravel validation rules |
| `src/Mcp/ResponseTruncator.php` | caps a scan result to `max_response_bytes` |
| `src/Mcp/ScannerTool.php` | abstract MCP tool wrapping one scanner |
| `src/Mcp/ReadOnlyScannerTool.php` | `#[IsReadOnly] #[IsIdempotent]` subclass |
| `src/Mcp/ActiveScannerTool.php` | plain subclass (no annotations) |
| `src/Mcp/ToolFactory.php` | `ScannerRegistry` → list of tools |
| `src/Mcp/DevToolboxServer.php` | the MCP server (`Laravel\Mcp\Server`) |
| `src/Mcp/McpRegistration.php` | pure decision "should the server be registered?" (config + env) |
| `src/LaravelDevtoolboxServiceProvider.php` | conditional `Mcp::local()` registration |
| `src/Console/Commands/DevAboutPlusCommand.php` | MCP status section |
| `resources/boost/guidelines/core.blade.php` | Boost guidelines |
| `resources/boost/skills/devtoolbox-analysis/SKILL.md` | Boost skill |
| `.github/workflows/tests.yml` | extra job without `laravel/mcp` |
| `README.md`, `CHANGELOG.md` | docs |

---

### Task 1: Dependencies, config and test groups

**Files:**
- Modify: `composer.json`
- Modify: `config/devtoolbox.php`
- Modify: `tests/Pest.php`
- Test: `tests/Unit/Mcp/ConfigTest.php`

**Interfaces:**
- Produces: config keys `devtoolbox.mcp.enabled`, `devtoolbox.mcp.environments`, `devtoolbox.mcp.max_response_bytes`; Pest group `mcp` for `tests/Unit/Mcp` and `tests/Feature/Mcp`.

- [ ] **Step 1: Add laravel/mcp to require-dev and suggest**

Run:
```bash
cd /Users/jean-marcstrauven/Dev/laravel-devtoolbox
git checkout feature/mcp-server && git pull --ff-only
composer require laravel/mcp:^1.0 --dev --no-interaction
```
Then edit `composer.json` by hand: add to `suggest`:
```json
"laravel/mcp": "Required to expose DevToolbox scanners as MCP tools for AI coding agents (php artisan mcp:start devtoolbox)"
```
and add `"mcp"`, `"ai"`, `"boost"` to `keywords`. Run `composer validate --strict` — expected `./composer.json is valid`.

- [ ] **Step 2: Add the config block**

Append to the array in `config/devtoolbox.php` (after the `export` block, before the closing `];`):

```php
    /*
    |--------------------------------------------------------------------------
    | MCP Server
    |--------------------------------------------------------------------------
    |
    | When laravel/mcp is installed, DevToolbox registers a local MCP server
    | ("devtoolbox") exposing every scanner as a tool for AI coding agents.
    | The server is only registered in the listed environments.
    |
    */
    'mcp' => [
        'enabled' => env('DEVTOOLBOX_MCP_ENABLED', true),
        'environments' => ['local', 'testing'],
        'max_response_bytes' => 262_144,
    ],
```

- [ ] **Step 3: Register the Pest group**

In `tests/Pest.php`, after the existing `uses()->beforeEach(...)` block, add:

```php
uses()->group('mcp')->in('Unit/Mcp', 'Feature/Mcp');
```

- [ ] **Step 4: Write the config test**

Create `tests/Unit/Mcp/ConfigTest.php`:

```php
<?php

declare(strict_types=1);

it('ships default mcp configuration', function (): void {
    expect(config('devtoolbox.mcp.enabled'))->toBeTrue()
        ->and(config('devtoolbox.mcp.environments'))->toBe(['local', 'testing'])
        ->and(config('devtoolbox.mcp.max_response_bytes'))->toBe(262_144);
});
```

- [ ] **Step 5: Run the test and the whole suite**

Run: `vendor/bin/pest tests/Unit/Mcp/ConfigTest.php`
Expected: 1 passed.
Run: `vendor/bin/pest --exclude-group mcp | tail -3`
Expected: 125 passed (existing suite untouched).

- [ ] **Step 6: Commit**

```bash
git add composer.json config/devtoolbox.php tests/Pest.php tests/Unit/Mcp/ConfigTest.php
git commit -m "chore: add laravel/mcp dev dependency and mcp config block"
```

---

### Task 2: Typed option schema on AbstractScanner with inference fallback

**Files:**
- Create: `src/Scanners/OptionSchemaInferrer.php`
- Modify: `src/Scanners/AbstractScanner.php`
- Test: `tests/Unit/Scanners/OptionSchemaInferrerTest.php`, `tests/Unit/Scanners/AbstractScannerOptionSchemaTest.php`

**Interfaces:**
- Produces:
  - `OptionSchemaInferrer::fromDescriptions(array<string,string> $options): array<string, array{type: string, description: string}>` (static)
  - `AbstractScanner::getOptionSchema(): array<string, array{type: 'boolean'|'string'|'integer'|'array'|'object', description: string, default?: mixed, enum?: list<string>, required?: bool}>`
  - `AbstractScanner::getAvailableOptions(): array<string,string>` now derived from the schema.

- [ ] **Step 1: Write the inferrer tests**

Create `tests/Unit/Scanners/OptionSchemaInferrerTest.php`:

```php
<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Scanners\OptionSchemaInferrer;

it('infers array from an "(array)" hint in the description', function (): void {
    $schema = OptionSchemaInferrer::fromDescriptions(['tables' => 'Specific tables to analyze (array)']);

    expect($schema['tables'])->toBe(['type' => 'array', 'description' => 'Specific tables to analyze (array)']);
});

it('infers string for well-known scalar keys', function (string $key): void {
    $schema = OptionSchemaInferrer::fromDescriptions([$key => 'Some description']);

    expect($schema[$key]['type'])->toBe('string');
})->with(['route', 'url', 'method', 'model', 'class', 'name', 'pattern', 'path', 'file', 'connection', 'table', 'column', 'target', 'filter', 'middleware', 'group_by']);

it('infers integer for well-known numeric keys', function (string $key): void {
    $schema = OptionSchemaInferrer::fromDescriptions([$key => 'Some description']);

    expect($schema[$key]['type'])->toBe('integer');
})->with(['limit', 'threshold', 'slow_threshold', 'top', 'lines', 'count']);

it('infers object from a "JSON object" hint', function (): void {
    $schema = OptionSchemaInferrer::fromDescriptions(['headers' => 'Request headers as JSON object']);

    expect($schema['headers']['type'])->toBe('object');
});

it('falls back to boolean', function (): void {
    $schema = OptionSchemaInferrer::fromDescriptions(['detect_unused' => 'Attempt to detect unused routes']);

    expect($schema['detect_unused']['type'])->toBe('boolean');
});

it('keeps the description verbatim and preserves order', function (): void {
    $schema = OptionSchemaInferrer::fromDescriptions(['b' => 'Second', 'a' => 'First']);

    expect(array_keys($schema))->toBe(['b', 'a'])
        ->and($schema['a']['description'])->toBe('First');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Scanners/OptionSchemaInferrerTest.php`
Expected: FAIL — `Class "Grazulex\LaravelDevtoolbox\Scanners\OptionSchemaInferrer" not found`.

- [ ] **Step 3: Implement the inferrer**

Create `src/Scanners/OptionSchemaInferrer.php`:

```php
<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

/**
 * Builds a typed option schema from the legacy "name => description" format,
 * for scanners that do not declare getOptionSchema() themselves.
 */
final class OptionSchemaInferrer
{
    private const STRING_KEYS = [
        'route', 'url', 'method', 'model', 'class', 'name', 'pattern', 'path', 'file',
        'connection', 'table', 'column', 'target', 'filter', 'middleware', 'group_by',
    ];

    private const INTEGER_KEYS = ['limit', 'threshold', 'slow_threshold', 'top', 'lines', 'count'];

    /**
     * @param  array<string, string>  $options
     * @return array<string, array{type: string, description: string}>
     */
    public static function fromDescriptions(array $options): array
    {
        $schema = [];

        foreach ($options as $name => $description) {
            $schema[$name] = [
                'type' => self::inferType($name, $description),
                'description' => $description,
            ];
        }

        return $schema;
    }

    private static function inferType(string $name, string $description): string
    {
        $lower = mb_strtolower($description);

        if (str_contains($lower, '(array)') || str_starts_with($lower, 'array of')) {
            return 'array';
        }

        if (str_contains($lower, 'json object')) {
            return 'object';
        }

        if (in_array($name, self::INTEGER_KEYS, true)) {
            return 'integer';
        }

        if (in_array($name, self::STRING_KEYS, true)) {
            return 'string';
        }

        return 'boolean';
    }
}
```

- [ ] **Step 4: Run the inferrer tests**

Run: `vendor/bin/pest tests/Unit/Scanners/OptionSchemaInferrerTest.php`
Expected: all passed.

- [ ] **Step 5: Write the AbstractScanner tests (three override cases, no recursion)**

Create `tests/Unit/Scanners/AbstractScannerOptionSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Scanners\AbstractScanner;

final class SchemaDeclaringScanner extends AbstractScanner
{
    public function getName(): string { return 'schema-declaring'; }
    public function getDescription(): string { return 'Declares its schema'; }
    public function scan(array $options = []): array { return []; }

    public function getOptionSchema(): array
    {
        return [
            'verbose' => ['type' => 'boolean', 'description' => 'Verbose output', 'default' => false],
            'limit' => ['type' => 'integer', 'description' => 'Max items'],
        ];
    }
}

final class LegacyOptionsScanner extends AbstractScanner
{
    public function getName(): string { return 'legacy'; }
    public function getDescription(): string { return 'Only declares legacy options'; }
    public function scan(array $options = []): array { return []; }

    public function getAvailableOptions(): array
    {
        return ['tables' => 'Tables to analyze (array)', 'unused_only' => 'Show only unused'];
    }
}

final class BareScanner extends AbstractScanner
{
    public function getName(): string { return 'bare'; }
    public function getDescription(): string { return 'Declares nothing'; }
    public function scan(array $options = []): array { return []; }
}

it('derives getAvailableOptions from a declared schema', function (): void {
    $scanner = new SchemaDeclaringScanner($this->app);

    expect($scanner->getAvailableOptions())->toBe(['verbose' => 'Verbose output', 'limit' => 'Max items']);
});

it('infers the schema from legacy getAvailableOptions', function (): void {
    $scanner = new LegacyOptionsScanner($this->app);

    expect($scanner->getOptionSchema())->toBe([
        'tables' => ['type' => 'array', 'description' => 'Tables to analyze (array)'],
        'unused_only' => ['type' => 'boolean', 'description' => 'Show only unused'],
    ]);
});

it('returns empty schema and options when nothing is declared', function (): void {
    $scanner = new BareScanner($this->app);

    expect($scanner->getOptionSchema())->toBe([])
        ->and($scanner->getAvailableOptions())->toBe([]);
});
```

- [ ] **Step 6: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Scanners/AbstractScannerOptionSchemaTest.php`
Expected: FAIL (the "derives" test fails because `getAvailableOptions()` is abstract in the interface and not implemented on `AbstractScanner`; PHP reports the class cannot be instantiated / abstract method not implemented).

- [ ] **Step 7: Implement on AbstractScanner**

In `src/Scanners/AbstractScanner.php`, add `use ReflectionMethod;` and these methods after `__construct`:

```php
    /**
     * Typed description of the options accepted by scan().
     *
     * Bundled scanners override this. Third-party scanners that only override
     * getAvailableOptions() get an inferred schema.
     *
     * @return array<string, array{
     *     type: 'boolean'|'string'|'integer'|'array'|'object',
     *     description: string,
     *     default?: mixed,
     *     enum?: list<string>,
     *     required?: bool
     * }>
     */
    public function getOptionSchema(): array
    {
        if (! $this->overrides('getAvailableOptions')) {
            return [];
        }

        return OptionSchemaInferrer::fromDescriptions($this->getAvailableOptions());
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableOptions(): array
    {
        return array_map(
            fn (array $option): string => $option['description'],
            $this->getOptionSchema(),
        );
    }

    private function overrides(string $method): bool
    {
        return (new ReflectionMethod($this, $method))->getDeclaringClass()->getName() !== self::class;
    }
```

- [ ] **Step 8: Run tests, static analysis, style**

Run: `vendor/bin/pest tests/Unit/Scanners/`
Expected: all passed.
Run: `vendor/bin/pest --exclude-group mcp | tail -3` — expected 125 + new tests passed (all 16 bundled scanners still override `getAvailableOptions()`, so behaviour is unchanged).
Run: `vendor/bin/phpstan analyse --no-progress` — expected `[OK] No errors`.
Run: `vendor/bin/pint --test` — expected `passed`.

- [ ] **Step 9: Commit**

```bash
git add src/Scanners/OptionSchemaInferrer.php src/Scanners/AbstractScanner.php tests/Unit/Scanners/
git commit -m "feat: typed option schema on scanners with inference fallback"
```

---

### Task 3: Declare explicit option schemas on the 16 bundled scanners

**Files:**
- Modify: every `src/Scanners/*Scanner.php` except `AbstractScanner.php` (replace `getAvailableOptions()` by `getOptionSchema()`)
- Test: `tests/Unit/Scanners/BundledScannerSchemaTest.php`

**Interfaces:**
- Consumes: `AbstractScanner::getOptionSchema()` (Task 2).
- Produces: each bundled scanner declares `getOptionSchema()`; `getAvailableOptions()` is no longer overridden anywhere in `src/Scanners/`.

- [ ] **Step 1: Write the parametrised test over the registry**

Create `tests/Unit/Scanners/BundledScannerSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Grazulex\LaravelDevtoolbox\Scanners\AbstractScanner;

const INTERNAL_OPTIONS = ['format', 'include_metadata', 'paths', 'exclude'];
const ALLOWED_TYPES = ['boolean', 'string', 'integer', 'array', 'object'];

function bundledScanners(): array
{
    $manager = new DevtoolboxManager(app());

    return array_map(fn (string $name): array => [$name], $manager->registry()->all());
}

it('declares an explicit typed schema', function (string $name): void {
    $scanner = (new DevtoolboxManager(app()))->registry()->get($name);

    $declaring = (new ReflectionMethod($scanner, 'getOptionSchema'))->getDeclaringClass()->getName();
    expect($declaring)->not->toBe(AbstractScanner::class, "$name must override getOptionSchema()");

    $legacy = (new ReflectionMethod($scanner, 'getAvailableOptions'))->getDeclaringClass()->getName();
    expect($legacy)->toBe(AbstractScanner::class, "$name must not override getAvailableOptions()");

    $schema = $scanner->getOptionSchema();
    expect($schema)->not->toBeEmpty();

    foreach ($schema as $option => $definition) {
        expect($option)->not->toBeIn(INTERNAL_OPTIONS, "$name exposes internal option $option");
        expect($definition['type'])->toBeIn(ALLOWED_TYPES, "$name.$option has invalid type");
        expect($definition['description'])->toBeString()->not->toBe('');

        if (isset($definition['enum'])) {
            expect($definition['type'])->toBe('string');
            expect($definition['enum'])->toBeArray()->not->toBeEmpty();
            if (array_key_exists('default', $definition)) {
                expect($definition['default'])->toBeIn($definition['enum']);
            }
        }

        if (array_key_exists('default', $definition)) {
            $expectedPhpType = match ($definition['type']) {
                'boolean' => 'bool', 'string' => 'string', 'integer' => 'int', default => 'array',
            };
            expect(get_debug_type($definition['default']))->toBe($expectedPhpType, "$name.$option default has wrong type");
        }
    }

    expect($scanner->getAvailableOptions())->toBe(array_map(fn (array $d): string => $d['description'], $schema));
})->with(bundledScanners());

it('registers exactly the expected scanners', function (): void {
    expect((new DevtoolboxManager(app()))->registry()->all())->toEqualCanonicalizing([
        'models', 'routes', 'route-where-lookup', 'container-bindings', 'middleware-usage',
        'sql-analysis', 'provider-timeline', 'commands', 'services', 'middleware', 'views',
        'model-usage', 'sql-trace', 'security', 'db-column-usage', 'performance',
    ]);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Scanners/BundledScannerSchemaTest.php`
Expected: 16 failures ("must override getOptionSchema()"), 1 pass.

- [ ] **Step 3: Replace `getAvailableOptions()` with `getOptionSchema()` in each scanner**

In each file below, delete the whole `public function getAvailableOptions(): array { … }` method and add the `getOptionSchema()` method shown. Keep the PHPDoc `@return` from `AbstractScanner` out (inherited). Note `PerformanceScanner` currently lists `format` — it is an internal option and is dropped.

`CommandScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'custom_only' => ['type' => 'boolean', 'description' => 'Show only custom (non-Laravel) commands', 'default' => false],
            'include_signatures' => ['type' => 'boolean', 'description' => 'Include command signatures and descriptions', 'default' => false],
            'group_by_namespace' => ['type' => 'boolean', 'description' => 'Group commands by their namespace', 'default' => false],
        ];
    }
```

`ContainerBindingsScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'filter' => ['type' => 'string', 'description' => 'Filter bindings by name, namespace, or type'],
            'show_resolved' => ['type' => 'boolean', 'description' => 'Attempt to resolve bindings and show actual instances', 'default' => false],
            'show_parameters' => ['type' => 'boolean', 'description' => 'Show constructor parameters for classes', 'default' => false],
            'show_aliases' => ['type' => 'boolean', 'description' => 'Include container aliases in output', 'default' => false],
            'group_by' => ['type' => 'string', 'description' => 'Group results by type, namespace or singleton', 'enum' => ['type', 'namespace', 'singleton']],
        ];
    }
```

`DatabaseColumnUsageScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'tables' => ['type' => 'array', 'description' => 'Specific tables to analyze'],
            'exclude_tables' => ['type' => 'array', 'description' => 'Tables to exclude from analysis'],
            'scan_paths' => ['type' => 'array', 'description' => 'Paths to scan for column usage'],
            'include_migrations' => ['type' => 'boolean', 'description' => 'Include migration files in usage analysis', 'default' => false],
            'unused_only' => ['type' => 'boolean', 'description' => 'Show only unused columns', 'default' => false],
            'check_fillable' => ['type' => 'boolean', 'description' => 'Check if columns are in model fillable arrays', 'default' => false],
        ];
    }
```

`MiddlewareScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'include_usage' => ['type' => 'boolean', 'description' => 'Include middleware usage in routes', 'default' => false],
            'group_by_type' => ['type' => 'boolean', 'description' => 'Group by global, route, and group middleware', 'default' => false],
        ];
    }
```

`MiddlewareUsageScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'middleware' => ['type' => 'string', 'description' => 'Specific middleware (alias or class) to analyze; all middleware when omitted'],
            'show_routes' => ['type' => 'boolean', 'description' => 'Include detailed route information', 'default' => false],
            'show_groups' => ['type' => 'boolean', 'description' => 'Include route group information', 'default' => false],
            'show_global' => ['type' => 'boolean', 'description' => 'Include global middleware', 'default' => false],
            'unused_only' => ['type' => 'boolean', 'description' => 'Show only unused middleware', 'default' => false],
        ];
    }
```

`ModelScanner.php` (`paths` is a genuine option here — `scan()` reads `$options['paths']` — so it stays in the schema; Step 4 removes it from the internal-options list of the test):
```php
    public function getOptionSchema(): array
    {
        return [
            'paths' => ['type' => 'array', 'description' => 'Paths to scan for models (default: app/Models)'],
            'include_relationships' => ['type' => 'boolean', 'description' => 'Include model relationships in results', 'default' => false],
            'include_attributes' => ['type' => 'boolean', 'description' => 'Include model attributes and fillable fields', 'default' => false],
            'include_scopes' => ['type' => 'boolean', 'description' => 'Include model scopes', 'default' => false],
        ];
    }
```

`ModelUsageScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'model' => ['type' => 'string', 'description' => 'The model class name or path to analyze', 'required' => true],
            'scan_controllers' => ['type' => 'boolean', 'description' => 'Scan controllers for model usage', 'default' => true],
            'scan_views' => ['type' => 'boolean', 'description' => 'Scan views for model usage', 'default' => true],
            'scan_routes' => ['type' => 'boolean', 'description' => 'Scan routes for model usage', 'default' => true],
            'scan_models' => ['type' => 'boolean', 'description' => 'Scan other models for relationships', 'default' => true],
            'scan_jobs' => ['type' => 'boolean', 'description' => 'Scan jobs for model usage', 'default' => true],
            'scan_observers' => ['type' => 'boolean', 'description' => 'Scan observers for model usage', 'default' => true],
        ];
    }
```
Before writing the defaults above, open `ModelUsageScanner::getDefaultOptions()` / `scan()` and copy the *actual* default of each `scan_*` flag (true or false). The schema default must equal the runtime default.

`PerformanceScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'route' => ['type' => 'string', 'description' => 'Specific named route to analyze; whole application when omitted'],
            'include_memory' => ['type' => 'boolean', 'description' => 'Include memory usage analysis', 'default' => true],
            'include_queries' => ['type' => 'boolean', 'description' => 'Include query performance analysis', 'default' => true],
            'include_cache' => ['type' => 'boolean', 'description' => 'Include cache analysis', 'default' => true],
        ];
    }
```

`ProviderTimelineScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'slow_threshold' => ['type' => 'integer', 'description' => 'Threshold in milliseconds to mark providers as slow', 'default' => 50],
            'include_deferred' => ['type' => 'boolean', 'description' => 'Include deferred providers in analysis', 'default' => false],
            'show_dependencies' => ['type' => 'boolean', 'description' => 'Show provider dependencies and load order', 'default' => false],
            'show_bindings' => ['type' => 'boolean', 'description' => 'Show services registered by each provider', 'default' => false],
        ];
    }
```

`RouteScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'group_by_middleware' => ['type' => 'boolean', 'description' => 'Group routes by their middleware', 'default' => false],
            'include_parameters' => ['type' => 'boolean', 'description' => 'Include route parameters information', 'default' => false],
            'detect_unused' => ['type' => 'boolean', 'description' => 'Attempt to detect unused routes', 'default' => false],
            'filter_methods' => ['type' => 'array', 'description' => 'Only include routes matching these HTTP methods (e.g. ["GET", "POST"])'],
            'strict_unused_detection' => ['type' => 'boolean', 'description' => 'Use strict detection (flags unprotected API routes too)', 'default' => false],
            'exclude_api_routes' => ['type' => 'boolean', 'description' => 'Exclude API routes from unused detection', 'default' => false],
        ];
    }
```

`RouteWhereLookupScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'target' => ['type' => 'string', 'description' => 'Controller class or Controller@method to search for', 'required' => true],
            'show_methods' => ['type' => 'boolean', 'description' => 'Show available methods in the target controller', 'default' => false],
            'include_parameters' => ['type' => 'boolean', 'description' => 'Include route parameters in results', 'default' => false],
        ];
    }
```

`SecurityScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'check_unprotected_routes' => ['type' => 'boolean', 'description' => 'Check for routes without authentication middleware', 'default' => true],
            'check_csrf_protection' => ['type' => 'boolean', 'description' => 'Check for routes without CSRF protection', 'default' => true],
            'exclude_patterns' => ['type' => 'array', 'description' => 'Route patterns to exclude from checks'],
            'critical_only' => ['type' => 'boolean', 'description' => 'Show only critical security issues', 'default' => false],
        ];
    }
```
(check the actual defaults of `check_*` in the scanner and align.)

`ServiceScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'include_singletons' => ['type' => 'boolean', 'description' => 'Include singleton services separately', 'default' => false],
            'include_aliases' => ['type' => 'boolean', 'description' => 'Include service aliases', 'default' => false],
            'filter_custom' => ['type' => 'boolean', 'description' => 'Show only custom (non-Laravel) services', 'default' => false],
        ];
    }
```

`SqlAnalysisScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'route' => ['type' => 'string', 'description' => 'Named route to analyze (route or url is required)'],
            'url' => ['type' => 'string', 'description' => 'URL path to analyze (route or url is required)'],
            'method' => ['type' => 'string', 'description' => 'HTTP method for the request', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'default' => 'GET'],
            'threshold' => ['type' => 'integer', 'description' => 'Duplicate query threshold', 'default' => 2],
            'auto_explain' => ['type' => 'boolean', 'description' => 'Run EXPLAIN on detected problematic queries', 'default' => false],
        ];
    }
```

`SqlTraceScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'route' => ['type' => 'string', 'description' => 'Named route to trace (route or url is required)'],
            'url' => ['type' => 'string', 'description' => 'URL path to trace (route or url is required)'],
            'method' => ['type' => 'string', 'description' => 'HTTP method', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'default' => 'GET'],
            'parameters' => ['type' => 'object', 'description' => 'Route parameters as a JSON object'],
            'headers' => ['type' => 'object', 'description' => 'Request headers as a JSON object'],
        ];
    }
```

`ViewScanner.php`:
```php
    public function getOptionSchema(): array
    {
        return [
            'detect_unused' => ['type' => 'boolean', 'description' => 'Attempt to detect unused views', 'default' => false],
            'include_components' => ['type' => 'boolean', 'description' => 'Include Blade components', 'default' => false],
            'view_paths' => ['type' => 'array', 'description' => 'Custom view paths to scan'],
        ];
    }
```

For every scanner: open its `getDefaultOptions()` (if any) and `scan()` and make each schema `default` equal to the value the scanner actually uses when the option is absent. If the scanner has no default for an option, omit `default`.

- [ ] **Step 4: Adjust the internal-option list in the test**

In `tests/Unit/Scanners/BundledScannerSchemaTest.php` change the constant to:
```php
const INTERNAL_OPTIONS = ['format', 'include_metadata', 'exclude'];
```
(`paths` is a genuine option of `ModelScanner`).

- [ ] **Step 5: Run the schema tests and the full suite**

Run: `vendor/bin/pest tests/Unit/Scanners/BundledScannerSchemaTest.php`
Expected: 17 passed.
Run: `vendor/bin/pest | tail -3`
Expected: all passed (any existing test asserting on `getAvailableOptions()` output still passes because descriptions are unchanged except the few reworded above — if one fails on a reworded description, update the expectation in that test to the new wording).
Run: `vendor/bin/phpstan analyse --no-progress && vendor/bin/pint --test`.

- [ ] **Step 6: Commit**

```bash
git add src/Scanners tests/Unit/Scanners/BundledScannerSchemaTest.php
git commit -m "feat: declare typed option schemas on all bundled scanners"
```

---

### Task 4: OptionSchemaCompiler (schema → JsonSchema + validation rules)

**Files:**
- Create: `src/Mcp/OptionSchemaCompiler.php`
- Test: `tests/Unit/Mcp/OptionSchemaCompilerTest.php`

**Interfaces:**
- Consumes: option schema arrays (Task 2 shape); `Illuminate\Contracts\JsonSchema\JsonSchema` factory (methods `string()`, `boolean()`, `integer()`, `array()`, `object()`; each type has `description()`, `required()`, `enum()`, `default()`).
- Produces:
  - `OptionSchemaCompiler::toJsonSchema(array $schema, JsonSchema $factory): array<string, \Illuminate\JsonSchema\Types\Type>`
  - `OptionSchemaCompiler::toValidationRules(array $schema): array<string, list<string>>`

- [ ] **Step 1: Write the tests**

Create `tests/Unit/Mcp/OptionSchemaCompilerTest.php`:

```php
<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Mcp\OptionSchemaCompiler;
use Illuminate\JsonSchema\JsonSchema;

const SAMPLE_SCHEMA = [
    'detect_unused' => ['type' => 'boolean', 'description' => 'Detect unused', 'default' => false],
    'method' => ['type' => 'string', 'description' => 'HTTP method', 'enum' => ['GET', 'POST'], 'default' => 'GET'],
    'threshold' => ['type' => 'integer', 'description' => 'Threshold', 'default' => 2],
    'tables' => ['type' => 'array', 'description' => 'Tables'],
    'headers' => ['type' => 'object', 'description' => 'Headers'],
    'target' => ['type' => 'string', 'description' => 'Target', 'required' => true],
];

it('compiles every option into a JSON schema property', function (): void {
    $compiled = JsonSchema::object(fn (JsonSchema $schema): array => (new OptionSchemaCompiler)->toJsonSchema(SAMPLE_SCHEMA, $schema))->toArray();

    expect($compiled['properties'])->toHaveKeys(array_keys(SAMPLE_SCHEMA))
        ->and($compiled['properties']['detect_unused'])->toMatchArray(['type' => 'boolean', 'description' => 'Detect unused', 'default' => false])
        ->and($compiled['properties']['method'])->toMatchArray(['type' => 'string', 'enum' => ['GET', 'POST'], 'default' => 'GET'])
        ->and($compiled['properties']['threshold'])->toMatchArray(['type' => 'integer', 'default' => 2])
        ->and($compiled['properties']['tables']['type'])->toBe('array')
        ->and($compiled['properties']['headers']['type'])->toBe('object')
        ->and($compiled['required'])->toBe(['target']);
});

it('compiles validation rules', function (): void {
    $rules = (new OptionSchemaCompiler)->toValidationRules(SAMPLE_SCHEMA);

    expect($rules)->toBe([
        'detect_unused' => ['sometimes', 'nullable', 'boolean'],
        'method' => ['sometimes', 'nullable', 'string', 'in:GET,POST'],
        'threshold' => ['sometimes', 'nullable', 'integer'],
        'tables' => ['sometimes', 'nullable', 'array'],
        'headers' => ['sometimes', 'nullable', 'array'],
        'target' => ['required', 'string'],
    ]);
});

it('compiles an empty schema to no properties and no rules', function (): void {
    $compiler = new OptionSchemaCompiler;

    expect(JsonSchema::object(fn (JsonSchema $s): array => $compiler->toJsonSchema([], $s))->toArray())->not->toHaveKey('required')
        ->and($compiler->toValidationRules([]))->toBe([]);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Mcp/OptionSchemaCompilerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

Create `src/Mcp/OptionSchemaCompiler.php`:

```php
<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Turns a scanner option schema (see AbstractScanner::getOptionSchema())
 * into an MCP input schema and into Laravel validation rules.
 */
final class OptionSchemaCompiler
{
    /**
     * @param  array<string, array{type: string, description: string, default?: mixed, enum?: list<string>, required?: bool}>  $schema
     * @return array<string, Type>
     */
    public function toJsonSchema(array $schema, JsonSchema $factory): array
    {
        $properties = [];

        foreach ($schema as $name => $definition) {
            $type = match ($definition['type']) {
                'boolean' => $factory->boolean(),
                'integer' => $factory->integer(),
                'array' => $factory->array(),
                'object' => $factory->object(),
                default => $factory->string(),
            };

            $type = $type->description($definition['description']);

            if (isset($definition['enum'])) {
                $type = $type->enum($definition['enum']);
            }

            if (array_key_exists('default', $definition)) {
                $type = $type->default($definition['default']);
            }

            if ($definition['required'] ?? false) {
                $type = $type->required();
            }

            $properties[$name] = $type;
        }

        return $properties;
    }

    /**
     * @param  array<string, array{type: string, description: string, default?: mixed, enum?: list<string>, required?: bool}>  $schema
     * @return array<string, list<string>>
     */
    public function toValidationRules(array $schema): array
    {
        $rules = [];

        foreach ($schema as $name => $definition) {
            $ruleSet = ($definition['required'] ?? false) ? ['required'] : ['sometimes', 'nullable'];

            $ruleSet[] = match ($definition['type']) {
                'boolean' => 'boolean',
                'integer' => 'integer',
                'array', 'object' => 'array',
                default => 'string',
            };

            if (isset($definition['enum'])) {
                $ruleSet[] = 'in:'.implode(',', $definition['enum']);
            }

            $rules[$name] = $ruleSet;
        }

        return $rules;
    }
}
```

If PHPStan complains that `default()` has a different signature per type (`BooleanType::default(bool)`, `StringType::default(string)`…), replace the single `$type->default(...)` call with a `match` on `$definition['type']` calling the concrete type's `default()`; do not silence the error with an ignore.

- [ ] **Step 4: Run tests, PHPStan, Pint**

Run: `vendor/bin/pest tests/Unit/Mcp/OptionSchemaCompilerTest.php` — expected 3 passed.
Run: `vendor/bin/phpstan analyse --no-progress && vendor/bin/pint --test`.

- [ ] **Step 5: Commit**

```bash
git add src/Mcp/OptionSchemaCompiler.php tests/Unit/Mcp/OptionSchemaCompilerTest.php
git commit -m "feat: compile scanner option schemas to MCP input schemas and validation rules"
```

---

### Task 5: ResponseTruncator

**Files:**
- Create: `src/Mcp/ResponseTruncator.php`
- Test: `tests/Unit/Mcp/ResponseTruncatorTest.php`

**Interfaces:**
- Produces: `ResponseTruncator::__construct(int $maxBytes)`, `ResponseTruncator::truncate(array $result, array $optionSchema): array`.

- [ ] **Step 1: Write the tests**

Create `tests/Unit/Mcp/ResponseTruncatorTest.php`:

```php
<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Mcp\ResponseTruncator;

function bigResult(int $items): array
{
    return [
        'count' => $items,
        'scanner' => 'routes',
        'routes' => array_map(fn (int $i): array => ['uri' => "/path/$i", 'name' => str_repeat('n', 40).$i], range(1, $items)),
        'grouped_by_middleware' => ['web' => range(1, $items)],
    ];
}

it('returns the result untouched when it fits', function (): void {
    $result = bigResult(3);

    expect((new ResponseTruncator(1_000_000))->truncate($result, []))->toBe($result);
});

it('truncates the first list, drops other big values and annotates', function (): void {
    $maxBytes = 2_000;
    $truncated = (new ResponseTruncator($maxBytes))->truncate(bigResult(500), ['detect_unused' => ['type' => 'boolean', 'description' => 'x'], 'filter_methods' => ['type' => 'array', 'description' => 'y']]);

    expect(strlen(json_encode($truncated)))->toBeLessThanOrEqual($maxBytes)
        ->and($truncated['count'])->toBe(500)
        ->and($truncated['scanner'])->toBe('routes')
        ->and($truncated['routes'])->toBeArray()->not->toBeEmpty()
        ->and(count($truncated['routes']))->toBeLessThan(500)
        ->and($truncated['grouped_by_middleware'])->toBeNull()
        ->and($truncated['_truncated'])->toBe([
            'original_items' => 500,
            'kept_items' => count($truncated['routes']),
            'hint' => 'Refine with options: detect_unused, filter_methods',
        ]);
});

it('keeps zero items when even one does not fit, and stays valid JSON', function (): void {
    $truncated = (new ResponseTruncator(120))->truncate(bigResult(50), []);

    expect(json_encode($truncated))->toBeString()
        ->and($truncated['_truncated']['kept_items'])->toBe(0)
        ->and($truncated['_truncated']['hint'])->toBe('No options available to refine this scan');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Mcp/ResponseTruncatorTest.php` — expected FAIL, class not found.

- [ ] **Step 3: Implement**

Create `src/Mcp/ResponseTruncator.php`:

```php
<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

/**
 * Keeps scanner results within a byte budget so they fit in an agent's context.
 *
 * Strategy: keep top-level scalars, keep the largest prefix of the first
 * top-level list that fits, null out other top-level arrays, and add a
 * "_truncated" note telling the agent how to refine the scan.
 */
final class ResponseTruncator
{
    public function __construct(private readonly int $maxBytes) {}

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, array{type: string, description: string}>  $optionSchema
     * @return array<string, mixed>
     */
    public function truncate(array $result, array $optionSchema): array
    {
        if ($this->size($result) <= $this->maxBytes) {
            return $result;
        }

        $listKey = null;
        $kept = [];

        foreach ($result as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $kept[$key] = $value;

                continue;
            }

            if ($listKey === null && is_array($value) && array_is_list($value)) {
                $listKey = $key;
                $kept[$key] = [];

                continue;
            }

            $kept[$key] = null;
        }

        $originalItems = $listKey === null ? 0 : count($result[$listKey]);
        $note = [
            'original_items' => $originalItems,
            'kept_items' => 0,
            'hint' => $optionSchema === []
                ? 'No options available to refine this scan'
                : 'Refine with options: '.implode(', ', array_keys($optionSchema)),
        ];
        $kept['_truncated'] = $note;

        if ($listKey === null) {
            return $kept;
        }

        $low = 0;
        $high = $originalItems;

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            $candidate = $kept;
            $candidate[$listKey] = array_slice($result[$listKey], 0, $mid);
            $candidate['_truncated']['kept_items'] = $mid;

            if ($this->size($candidate) <= $this->maxBytes) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        $kept[$listKey] = array_slice($result[$listKey], 0, $low);
        $kept['_truncated']['kept_items'] = $low;

        return $kept;
    }

    private function size(array $data): int
    {
        return strlen((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
```

- [ ] **Step 4: Run tests, PHPStan, Pint**

Run: `vendor/bin/pest tests/Unit/Mcp/ResponseTruncatorTest.php` — expected 3 passed.
Run: `vendor/bin/phpstan analyse --no-progress && vendor/bin/pint --test`.

- [ ] **Step 5: Commit**

```bash
git add src/Mcp/ResponseTruncator.php tests/Unit/Mcp/ResponseTruncatorTest.php
git commit -m "feat: byte-budget truncation for MCP scanner responses"
```

---

### Task 6: ScannerTool, its two subclasses and ToolFactory

**Files:**
- Create: `src/Mcp/ScannerTool.php`, `src/Mcp/ReadOnlyScannerTool.php`, `src/Mcp/ActiveScannerTool.php`, `src/Mcp/ToolFactory.php`
- Test: `tests/Unit/Mcp/ScannerToolTest.php`, `tests/Unit/Mcp/ToolFactoryTest.php`

**Interfaces:**
- Consumes: `OptionSchemaCompiler` (Task 4), `ResponseTruncator` (Task 5), `ScannerRegistry::getScanners(): array<string, ScannerInterface>`.
- Produces:
  - `abstract class ScannerTool extends Laravel\Mcp\Server\Tool` with `__construct(ScannerInterface $scanner, string $name, string $title, string $description, OptionSchemaCompiler $compiler, ResponseTruncator $truncator)`, `scanner(): ScannerInterface`, `disable(string $reason): void`, `schema()`, `handle(Request $request): Response|ResponseFactory`.
  - `final class ReadOnlyScannerTool extends ScannerTool` (`#[IsReadOnly] #[IsIdempotent]`), `final class ActiveScannerTool extends ScannerTool`.
  - `final class ToolFactory` with `public const ACTIVE_SCANNERS = ['sql-trace', 'sql-analysis', 'performance']`, `__construct(OptionSchemaCompiler $compiler, ResponseTruncator $truncator)`, `make(ScannerRegistry $registry): list<ScannerTool>`.

- [ ] **Step 1: Write the ScannerTool tests**

Create `tests/Unit/Mcp/ScannerToolTest.php`:

```php
<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Contracts\ScannerInterface;
use Grazulex\LaravelDevtoolbox\Mcp\ActiveScannerTool;
use Grazulex\LaravelDevtoolbox\Mcp\OptionSchemaCompiler;
use Grazulex\LaravelDevtoolbox\Mcp\ReadOnlyScannerTool;
use Grazulex\LaravelDevtoolbox\Mcp\ResponseTruncator;
use Grazulex\LaravelDevtoolbox\Scanners\AbstractScanner;

final class FakeScanner extends AbstractScanner implements ScannerInterface
{
    public array $received = [];

    public function __construct(private readonly array $result = ['count' => 1, 'items' => ['a']], private readonly ?Throwable $throws = null)
    {
        parent::__construct(app());
    }

    public function getName(): string { return 'fake'; }
    public function getDescription(): string { return 'A fake scanner'; }

    public function getOptionSchema(): array
    {
        return [
            'verbose' => ['type' => 'boolean', 'description' => 'Verbose', 'default' => false],
            'target' => ['type' => 'string', 'description' => 'Target', 'required' => true],
        ];
    }

    public function scan(array $options = []): array
    {
        $this->received = $options;
        if ($this->throws instanceof Throwable) {
            throw $this->throws;
        }

        return $this->result;
    }
}

function makeTool(FakeScanner $scanner, string $class = ReadOnlyScannerTool::class): ReadOnlyScannerTool|ActiveScannerTool
{
    return new $class($scanner, 'devtoolbox-fake', 'Fake', 'A fake scanner', new OptionSchemaCompiler, new ResponseTruncator(262_144));
}

it('exposes name, title, description and input schema', function (): void {
    $tool = makeTool(new FakeScanner);
    $array = $tool->toArray();

    expect($tool->name())->toBe('devtoolbox-fake')
        ->and($tool->title())->toBe('Fake')
        ->and($tool->description())->toBe('A fake scanner')
        ->and($array['inputSchema']['properties'])->toHaveKeys(['verbose', 'target'])
        ->and($array['inputSchema']['required'])->toBe(['target']);
});

it('marks read-only tools with annotations and active tools without', function (): void {
    expect(makeTool(new FakeScanner)->annotations())->toMatchArray(['readOnlyHint' => true, 'idempotentHint' => true])
        ->and(makeTool(new FakeScanner, ActiveScannerTool::class)->annotations())->toBe([]);
});

it('forwards validated options to the scanner with format=array', function (): void {
    $scanner = new FakeScanner;
    $tool = makeTool($scanner);

    $response = $tool->handle(new \Laravel\Mcp\Request(['target' => 'App\\Models\\User', 'verbose' => true]));

    expect($scanner->received)->toBe(['target' => 'App\\Models\\User', 'verbose' => true, 'format' => 'array'])
        ->and($response)->toBeInstanceOf(\Laravel\Mcp\ResponseFactory::class);
});

it('returns an error response when the scanner throws', function (): void {
    $tool = makeTool(new FakeScanner(throws: new RuntimeException('boom')));

    $response = $tool->handle(new \Laravel\Mcp\Request(['target' => 'x']));

    expect($response)->toBeInstanceOf(\Laravel\Mcp\Response::class)
        ->and($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('fake: boom');
});

it('returns a json response for an empty scan result', function (): void {
    $tool = makeTool(new FakeScanner(result: []));

    $response = $tool->handle(new \Laravel\Mcp\Request(['target' => 'x']));

    expect($response)->toBeInstanceOf(\Laravel\Mcp\Response::class)
        ->and($response->isError())->toBeFalse();
});

it('refuses to run once disabled', function (): void {
    $scanner = new FakeScanner;
    $tool = makeTool($scanner);
    $tool->disable('DevToolbox MCP is disabled in this environment.');

    $response = $tool->handle(new \Laravel\Mcp\Request(['target' => 'x']));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('disabled in this environment')
        ->and($scanner->received)->toBe([]);
});
```

Before running: check `Laravel\Mcp\Response` for the exact accessor names (`isError()` and `content()` are the documented ones in v1.0; if `content()` returns a `Content` object, cast with `(string)` as written, otherwise adapt to `->content()->toArray()['text']`). Check `Laravel\Mcp\Request::__construct` accepts an arguments array; if it needs more parameters, construct it the way `PendingTestResponse::run()` does (read `vendor/laravel/mcp/src/Server/Testing/PendingTestResponse.php`).

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Mcp/ScannerToolTest.php` — expected FAIL, classes not found.

- [ ] **Step 3: Implement ScannerTool and subclasses**

Create `src/Mcp/ScannerTool.php`:

```php
<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Grazulex\LaravelDevtoolbox\Contracts\ScannerInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Adapts one DevToolbox scanner to an MCP tool.
 */
abstract class ScannerTool extends Tool
{
    private ?string $disabledReason = null;

    public function __construct(
        private readonly ScannerInterface $scanner,
        string $name,
        string $title,
        string $description,
        private readonly OptionSchemaCompiler $compiler,
        private readonly ResponseTruncator $truncator,
    ) {
        $this->name = $name;
        $this->title = $title;
        $this->description = $description;
    }

    public function scanner(): ScannerInterface
    {
        return $this->scanner;
    }

    public function disable(string $reason): void
    {
        $this->disabledReason = $reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->compiler->toJsonSchema($this->optionSchema(), $schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($this->disabledReason !== null) {
            return Response::error($this->disabledReason);
        }

        $options = $request->validate($this->compiler->toValidationRules($this->optionSchema()));

        try {
            $result = $this->scanner->scan($options + ['format' => 'array']);
        } catch (Throwable $exception) {
            Log::debug('[devtoolbox.mcp] scanner failed', [
                'scanner' => $this->scanner->getName(),
                'exception' => $exception,
            ]);

            return Response::error(sprintf('%s: %s', $this->scanner->getName(), $exception->getMessage()));
        }

        if ($result === []) {
            return Response::json([]);
        }

        return Response::structured($this->truncator->truncate($result, $this->optionSchema()));
    }

    /**
     * @return array<string, array{type: string, description: string, default?: mixed, enum?: list<string>, required?: bool}>
     */
    private function optionSchema(): array
    {
        return method_exists($this->scanner, 'getOptionSchema')
            ? $this->scanner->getOptionSchema()
            : \Grazulex\LaravelDevtoolbox\Scanners\OptionSchemaInferrer::fromDescriptions($this->scanner->getAvailableOptions());
    }
}
```

Create `src/Mcp/ReadOnlyScannerTool.php`:

```php
<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
final class ReadOnlyScannerTool extends ScannerTool {}
```

Create `src/Mcp/ActiveScannerTool.php`:

```php
<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

/**
 * Tool for scanners that execute code (internal HTTP request, queries…).
 * Deliberately carries no read-only annotation.
 */
final class ActiveScannerTool extends ScannerTool {}
```

- [ ] **Step 4: Run the ScannerTool tests**

Run: `vendor/bin/pest tests/Unit/Mcp/ScannerToolTest.php` — expected 6 passed. Fix accessor names per the note in Step 1 if needed.

- [ ] **Step 5: Write the ToolFactory tests**

Create `tests/Unit/Mcp/ToolFactoryTest.php`:

```php
<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Grazulex\LaravelDevtoolbox\Mcp\ActiveScannerTool;
use Grazulex\LaravelDevtoolbox\Mcp\OptionSchemaCompiler;
use Grazulex\LaravelDevtoolbox\Mcp\ReadOnlyScannerTool;
use Grazulex\LaravelDevtoolbox\Mcp\ResponseTruncator;
use Grazulex\LaravelDevtoolbox\Mcp\ToolFactory;

function factory(): ToolFactory
{
    return new ToolFactory(new OptionSchemaCompiler, new ResponseTruncator(262_144));
}

it('builds one tool per registered scanner with a devtoolbox- prefix', function (): void {
    $registry = (new DevtoolboxManager(app()))->registry();

    $tools = factory()->make($registry);

    expect($tools)->toHaveCount(16)
        ->and(array_map(fn ($tool): string => $tool->name(), $tools))
        ->toEqualCanonicalizing(array_map(fn (string $name): string => "devtoolbox-$name", $registry->all()));
});

it('uses the scanner description and a headline title', function (): void {
    $tools = collect(factory()->make((new DevtoolboxManager(app()))->registry()))->keyBy(fn ($tool): string => $tool->name());

    expect($tools['devtoolbox-route-where-lookup']->title())->toBe('Route Where Lookup')
        ->and($tools['devtoolbox-routes']->description())->toBe('Scan Laravel routes and analyze their usage');
});

it('marks exactly sql-trace, sql-analysis and performance as active', function (): void {
    $tools = factory()->make((new DevtoolboxManager(app()))->registry());

    $active = array_values(array_map(
        fn ($tool): string => $tool->scanner()->getName(),
        array_filter($tools, fn ($tool): bool => $tool instanceof ActiveScannerTool),
    ));
    $readOnly = array_filter($tools, fn ($tool): bool => $tool instanceof ReadOnlyScannerTool);

    expect($active)->toEqualCanonicalizing(['sql-trace', 'sql-analysis', 'performance'])
        ->and($readOnly)->toHaveCount(13);
});
```

- [ ] **Step 6: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Mcp/ToolFactoryTest.php` — expected FAIL, `ToolFactory` not found.

- [ ] **Step 7: Implement ToolFactory**

Create `src/Mcp/ToolFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Grazulex\LaravelDevtoolbox\Registry\ScannerRegistry;
use Illuminate\Support\Str;

final class ToolFactory
{
    /**
     * Scanners that execute code (internal request, queries) rather than only
     * reading application metadata. They are exposed without read-only hints.
     *
     * @var list<string>
     */
    public const ACTIVE_SCANNERS = ['sql-trace', 'sql-analysis', 'performance'];

    public function __construct(
        private readonly OptionSchemaCompiler $compiler,
        private readonly ResponseTruncator $truncator,
    ) {}

    /**
     * @return list<ScannerTool>
     */
    public function make(ScannerRegistry $registry): array
    {
        $tools = [];

        foreach ($registry->getScanners() as $name => $scanner) {
            $class = in_array($name, self::ACTIVE_SCANNERS, true) ? ActiveScannerTool::class : ReadOnlyScannerTool::class;

            $tools[] = new $class(
                $scanner,
                'devtoolbox-'.$name,
                Str::headline($name),
                $scanner->getDescription(),
                $this->compiler,
                $this->truncator,
            );
        }

        return $tools;
    }
}
```

- [ ] **Step 8: Run tests, PHPStan, Pint**

Run: `vendor/bin/pest tests/Unit/Mcp/` — expected all passed.
Run: `vendor/bin/phpstan analyse --no-progress && vendor/bin/pint --test`.

- [ ] **Step 9: Commit**

```bash
git add src/Mcp tests/Unit/Mcp
git commit -m "feat: MCP tools wrapping DevToolbox scanners"
```

---

### Task 7: DevToolboxServer, registration guard and end-to-end tool tests

**Files:**
- Create: `src/Mcp/DevToolboxServer.php`, `src/Mcp/McpRegistration.php`
- Modify: `src/LaravelDevtoolboxServiceProvider.php`
- Test: `tests/Unit/Mcp/McpRegistrationTest.php`, `tests/Feature/Mcp/DevToolboxServerTest.php`

**Interfaces:**
- Consumes: `ToolFactory` (Task 6), `DevtoolboxManager::registry()`.
- Produces:
  - `McpRegistration::shouldRegister(\Illuminate\Contracts\Foundation\Application $app): bool` (static), `McpRegistration::isEnvironmentAllowed(Application $app): bool` (static).
  - `DevToolboxServer` registered as local MCP server `devtoolbox`.

- [ ] **Step 1: Write the registration decision tests**

Create `tests/Unit/Mcp/McpRegistrationTest.php`:

```php
<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Mcp\McpRegistration;
use Laravel\Mcp\Facades\Mcp;

it('registers by default in the testing environment', function (): void {
    expect(McpRegistration::shouldRegister($this->app))->toBeTrue()
        ->and(Mcp::getLocalServer('devtoolbox'))->not->toBeNull();
});

it('does not register when disabled', function (): void {
    config(['devtoolbox.mcp.enabled' => false]);

    expect(McpRegistration::shouldRegister($this->app))->toBeFalse();
});

it('does not register outside allowed environments', function (): void {
    config(['devtoolbox.mcp.environments' => ['local']]);

    expect(McpRegistration::isEnvironmentAllowed($this->app))->toBeFalse()
        ->and(McpRegistration::shouldRegister($this->app))->toBeFalse();
});

it('does not register without laravel/mcp', function (): void {
    expect(McpRegistration::shouldRegister($this->app, mcpInstalled: false))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Mcp/McpRegistrationTest.php` — expected FAIL, class not found.

- [ ] **Step 3: Implement McpRegistration and DevToolboxServer, wire the provider**

Create `src/Mcp/McpRegistration.php`:

```php
<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Illuminate\Contracts\Foundation\Application;

/**
 * Decides whether the DevToolbox MCP server may be registered. Pure, so the
 * service provider stays trivial and the rule is unit-testable.
 */
final class McpRegistration
{
    public static function shouldRegister(Application $app, ?bool $mcpInstalled = null): bool
    {
        $mcpInstalled ??= class_exists(\Laravel\Mcp\Facades\Mcp::class);

        return $mcpInstalled
            && (bool) $app['config']->get('devtoolbox.mcp.enabled', true)
            && self::isEnvironmentAllowed($app);
    }

    public static function isEnvironmentAllowed(Application $app): bool
    {
        /** @var list<string> $environments */
        $environments = $app['config']->get('devtoolbox.mcp.environments', ['local', 'testing']);

        return $app->environment($environments);
    }
}
```

Create `src/Mcp/DevToolboxServer.php`:

```php
<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Composer\InstalledVersions;
use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\Transport;
use OutOfBoundsException;

#[Name('DevToolbox')]
#[Instructions('Read-only introspection of this Laravel application: routes, models, middleware, container bindings, views, providers, security checks. The sql-trace, sql-analysis and performance tools actually execute an internal request against the application; use them only on local environments.')]
final class DevToolboxServer extends Server
{
    protected string $version;

    /** @var list<ScannerTool> */
    private array $scannerTools;

    public function __construct(
        Transport $transport,
        ToolFactory $factory,
        DevtoolboxManager $manager,
        private readonly Application $app,
    ) {
        parent::__construct($transport);

        $this->version = self::packageVersion();
        $this->scannerTools = $factory->make($manager->registry());
        $this->tools = $this->scannerTools;
    }

    protected function boot(): void
    {
        if (McpRegistration::isEnvironmentAllowed($this->app)) {
            return;
        }

        foreach ($this->scannerTools as $tool) {
            $tool->disable('DevToolbox MCP is disabled in this environment.');
        }
    }

    private static function packageVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('grazulex/laravel-devtoolbox') ?? 'dev';
        } catch (OutOfBoundsException) {
            return 'dev';
        }
    }
}
```

Check `Laravel\Mcp\Server` for the exact name of the version fallback property (`protected string $version` is what `createContext()` reads when no `#[Version]` attribute is present — confirm in `vendor/laravel/mcp/src/Server.php`; if the property has a different name, use that one).

Also verify `ToolFactory` is container-resolvable: `ResponseTruncator` needs an int. Add to `LaravelDevtoolboxServiceProvider::register()`:

```php
        $this->app->bind(\Grazulex\LaravelDevtoolbox\Mcp\ResponseTruncator::class, fn ($app): \Grazulex\LaravelDevtoolbox\Mcp\ResponseTruncator => new \Grazulex\LaravelDevtoolbox\Mcp\ResponseTruncator(
            (int) $app['config']->get('devtoolbox.mcp.max_response_bytes', 262_144),
        ));
```
(`ResponseTruncator` has no laravel/mcp dependency, so this binding is safe without the package.)

Add to `LaravelDevtoolboxServiceProvider::boot()` (at the end):

```php
        if (McpRegistration::shouldRegister($this->app)) {
            \Laravel\Mcp\Facades\Mcp::local('devtoolbox', \Grazulex\LaravelDevtoolbox\Mcp\DevToolboxServer::class);
        }
```
with `use Grazulex\LaravelDevtoolbox\Mcp\McpRegistration;` at the top. The facade and server class are referenced as fully-qualified strings inside the guarded block only.

- [ ] **Step 4: Run the registration tests**

Run: `vendor/bin/pest tests/Unit/Mcp/McpRegistrationTest.php` — expected 4 passed.

- [ ] **Step 5: Write the end-to-end server tests**

Create `tests/Feature/Mcp/DevToolboxServerTest.php`:

```php
<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Grazulex\LaravelDevtoolbox\Mcp\DevToolboxServer;
use Grazulex\LaravelDevtoolbox\Mcp\ToolFactory;
use Illuminate\Support\Facades\Route;

function tool(string $name)
{
    $tools = app(ToolFactory::class)->make(app(DevtoolboxManager::class)->registry());

    foreach ($tools as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("Tool $name not built");
}

beforeEach(function (): void {
    Route::get('/mcp-test/users', fn (): array => ['ok' => true])->name('mcp-test.users')->middleware('auth');
    Route::get('/mcp-test/public', fn (): array => ['ok' => true])->name('mcp-test.public');
});

it('lists the sixteen devtoolbox tools', function (): void {
    DevToolboxServer::tools()->assertCount(16)->assertSee('devtoolbox-routes');
});

it('runs the routes tool', function (): void {
    DevToolboxServer::tool(tool('devtoolbox-routes'), ['detect_unused' => false])
        ->assertOk()
        ->assertSee('mcp-test.users');
});

it('runs the models and middleware-usage tools', function (): void {
    DevToolboxServer::tool(tool('devtoolbox-models'), [])->assertOk();
    DevToolboxServer::tool(tool('devtoolbox-middleware-usage'), ['middleware' => 'auth', 'show_routes' => true])
        ->assertOk()
        ->assertSee('mcp-test.users');
});

it('runs the active sql-trace tool against a named route', function (): void {
    DevToolboxServer::tool(tool('devtoolbox-sql-trace'), ['route' => 'mcp-test.public'])->assertOk();
});

it('rejects arguments of the wrong type', function (): void {
    DevToolboxServer::tool(tool('devtoolbox-routes'), ['detect_unused' => 'yes please'])->assertHasErrors();
});

it('rejects a missing required argument', function (): void {
    DevToolboxServer::tool(tool('devtoolbox-model-usage'), [])->assertHasErrors();
});
```

If `DevToolboxServer::tools()->assertCount()` does not exist on `TestListResponse`, read `vendor/laravel/mcp/src/Server/Testing/TestListResponse.php` and use the available assertion (e.g. `->assertSee()` on each name, or count the decoded list).

- [ ] **Step 6: Run the feature tests**

Run: `vendor/bin/pest tests/Feature/Mcp/DevToolboxServerTest.php` — expected 6 passed. If `sql-trace` fails because the testbench app has no session/DB in the request cycle, keep the test but point it at `mcp-test.public` with `'method' => 'GET'` and read the scanner's own feature test (`tests/Feature/SqlTraceScannerTest.php`) for the setup it uses.

- [ ] **Step 7: Full suite, PHPStan, Pint**

Run: `vendor/bin/pest | tail -3` — all passed.
Run: `vendor/bin/phpstan analyse --no-progress && vendor/bin/pint --test`.

- [ ] **Step 8: Manual smoke test with the inspector**

Run: `php vendor/bin/testbench mcp:inspector devtoolbox` (or, if the testbench skeleton can't start it, skip and note it in the commit body). Expected: the inspector lists 16 tools.

- [ ] **Step 9: Commit**

```bash
git add src/Mcp/DevToolboxServer.php src/Mcp/McpRegistration.php src/LaravelDevtoolboxServiceProvider.php tests/Unit/Mcp/McpRegistrationTest.php tests/Feature/Mcp/DevToolboxServerTest.php
git commit -m "feat: register the DevToolbox MCP server in local and testing environments"
```

---

### Task 8: MCP status in `dev:about+`

**Files:**
- Modify: `src/Console/Commands/DevAboutPlusCommand.php`
- Test: `tests/Feature/Commands/DevAboutPlusMcpTest.php`

**Interfaces:**
- Consumes: `McpRegistration::isEnvironmentAllowed()`.
- Produces: an `mcp` section in the gathered information: `['installed' => bool, 'enabled' => bool, 'environment_allowed' => bool, 'server' => 'devtoolbox', 'start_command' => 'php artisan mcp:start devtoolbox']`.

- [ ] **Step 1: Write the test**

Create `tests/Feature/Commands/DevAboutPlusMcpTest.php`:

```php
<?php

declare(strict_types=1);

it('reports the mcp server status in json output', function (): void {
    \Illuminate\Support\Facades\Artisan::call('dev:about+', ['--format' => 'json']);
    $json = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

    expect($json['mcp'])->toMatchArray([
        'installed' => class_exists(\Laravel\Mcp\Facades\Mcp::class),
        'enabled' => true,
        'environment_allowed' => true,
        'server' => 'devtoolbox',
        'start_command' => 'php artisan mcp:start devtoolbox',
    ]);
});
```

Check `DevAboutPlusCommand`'s signature for the exact name and values of the format option (`--format=json` is used across the package) and adapt the call if it differs.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Commands/DevAboutPlusMcpTest.php` — expected FAIL (`mcp` key missing).

- [ ] **Step 3: Implement**

In `DevAboutPlusCommand::gatherInformation()`, add `'mcp' => $this->getMcpInfo(),` after `'dependencies' => …`, and add the method:

```php
    private function getMcpInfo(): array
    {
        return [
            'installed' => class_exists(\Laravel\Mcp\Facades\Mcp::class),
            'enabled' => (bool) config('devtoolbox.mcp.enabled', true),
            'environment_allowed' => McpRegistration::isEnvironmentAllowed($this->laravel),
            'server' => 'devtoolbox',
            'start_command' => 'php artisan mcp:start devtoolbox',
        ];
    }
```
with `use Grazulex\LaravelDevtoolbox\Mcp\McpRegistration;`. Make sure the table renderer of the command prints the new section (it iterates the gathered array — verify by running `php vendor/bin/testbench dev:about+` and reading the output).

- [ ] **Step 4: Run tests, PHPStan, Pint**

Run: `vendor/bin/pest tests/Feature/Commands/DevAboutPlusMcpTest.php` and the full suite; `vendor/bin/phpstan analyse --no-progress && vendor/bin/pint --test`.

- [ ] **Step 5: Commit**

```bash
git add src/Console/Commands/DevAboutPlusCommand.php tests/Feature/Commands/DevAboutPlusMcpTest.php
git commit -m "feat: show MCP server status in dev:about+"
```

---

### Task 9: Boost guidelines and skill

**Files:**
- Create: `resources/boost/guidelines/core.blade.php`, `resources/boost/skills/devtoolbox-analysis/SKILL.md`
- Test: `tests/Unit/Mcp/BoostResourcesTest.php`

**Interfaces:**
- Produces: files discovered by `php artisan boost:install` in consuming apps.

- [ ] **Step 1: Write the test**

Create `tests/Unit/Mcp/BoostResourcesTest.php`:

```php
<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;

it('ships boost guidelines mentioning every tool', function (): void {
    $guidelines = file_get_contents(__DIR__.'/../../../resources/boost/guidelines/core.blade.php');

    foreach ((new DevtoolboxManager(app()))->registry()->all() as $name) {
        expect($guidelines)->toContain("devtoolbox-$name");
    }

    expect($guidelines)->toContain('mcp:start devtoolbox')->toContain('--format=json');
});

it('ships a boost skill with valid frontmatter', function (): void {
    $skill = file_get_contents(__DIR__.'/../../../resources/boost/skills/devtoolbox-analysis/SKILL.md');

    expect($skill)->toStartWith("---\nname: devtoolbox-analysis\ndescription: ")
        ->toContain('## When to use this skill')
        ->toContain('devtoolbox-sql-analysis')
        ->toContain('devtoolbox-model-usage')
        ->toContain('devtoolbox-provider-timeline');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Mcp/BoostResourcesTest.php` — expected FAIL (files missing).

- [ ] **Step 3: Write the guidelines**

Create `resources/boost/guidelines/core.blade.php`:

```blade
## Laravel DevToolbox

DevToolbox inspects this Laravel application: routes, models, middleware, container bindings, views, service providers, security checks and SQL behaviour. Prefer its tools over grepping the codebase when you need facts about the running application.

### MCP tools (when the `devtoolbox` MCP server is configured)

Read-only tools:
- `devtoolbox-routes`: list routes with middleware; `detect_unused: true` flags routes that look unused.
- `devtoolbox-route-where-lookup`: routes pointing to a controller or `Controller@method` (`target` is required).
- `devtoolbox-models`: Eloquent models with relationships, attributes and scopes.
- `devtoolbox-model-usage`: where a model is used (controllers, views, routes, jobs, observers) — `model` is required.
- `devtoolbox-db-column-usage`: which database columns are referenced in code; `unused_only: true` for dead columns.
- `devtoolbox-middleware`, `devtoolbox-middleware-usage`: registered middleware and where each one is applied.
- `devtoolbox-container-bindings`, `devtoolbox-services`: what the service container knows.
- `devtoolbox-commands`, `devtoolbox-views`: Artisan commands and Blade views (`detect_unused` for views).
- `devtoolbox-provider-timeline`: service provider boot order and timings (`slow_threshold` in ms).
- `devtoolbox-security`: routes without authentication or CSRF protection.

Tools that execute an internal request (local environments only):
- `devtoolbox-sql-trace`: run one route or URL and return every SQL query (`route` or `url` is required).
- `devtoolbox-sql-analysis`: run one route or URL and report duplicate / N+1 queries.
- `devtoolbox-performance`: memory, query and cache figures for a route or the whole app.

### Rules

- Before editing or deleting a route, call `devtoolbox-routes` with `detect_unused: true` and `devtoolbox-route-where-lookup` for its controller.
- Before changing a model or a column, call `devtoolbox-model-usage` and `devtoolbox-db-column-usage` to measure the impact.
- For a security review, start with `devtoolbox-security`, then `devtoolbox-middleware-usage` for the middleware involved.
- For a slow endpoint, run `devtoolbox-sql-analysis` first, then `devtoolbox-sql-trace` on the same route to see the exact queries.
- Results may carry a `_truncated` key: refine the call with the listed options instead of asking for "everything".

### Without MCP

Every tool has an Artisan equivalent that prints JSON, for example `php artisan dev:routes --format=json` or `php artisan dev:model:where-used User --format=json`. The MCP server itself starts with `php artisan mcp:start devtoolbox` (requires `laravel/mcp`).
```

- [ ] **Step 4: Write the skill**

Create `resources/boost/skills/devtoolbox-analysis/SKILL.md`:

```markdown
---
name: devtoolbox-analysis
description: Analyse a Laravel application with DevToolbox — unused or unprotected routes, N+1 queries, model and column impact, slow service providers.
---

# DevToolbox Analysis

## When to use this skill

Use this skill when you need facts about the running Laravel application rather than the source text: which routes exist and how they are protected, where a model is used, what SQL a request runs, why the application boots slowly. All examples below name MCP tools; each has an Artisan twin (`php artisan dev:… --format=json`).

## Recipes

### 1. Unprotected or unused routes

1. `devtoolbox-security` with `check_unprotected_routes: true` → list of routes without auth middleware.
2. For each suspicious route, `devtoolbox-route-where-lookup` with `target` set to its controller to see every route hitting that code.
3. `devtoolbox-routes` with `detect_unused: true` before removing anything; treat `unused` as a hint, not a proof.

### 2. N+1 and duplicate queries

1. `devtoolbox-sql-analysis` with `route` (or `url`) and the HTTP `method` → duplicate query groups and a threshold count.
2. `devtoolbox-sql-trace` on the same route → the ordered list of queries with bindings; look for the same statement repeated with different ids.
3. Fix with eager loading (`with()`), then re-run step 1 and compare counts.

These two tools execute the request inside the application: only use them in local environments.

### 3. Impact of a model or column change

1. `devtoolbox-model-usage` with `model` (class name) → controllers, views, routes, jobs and observers touching it.
2. `devtoolbox-db-column-usage` with `tables: ["<table>"]` → which columns are referenced in code and migrations; `unused_only: true` lists candidates for removal.
3. Only then edit the model, the migration and the callers found above.

### 4. Slow boot

1. `devtoolbox-provider-timeline` with `slow_threshold: 20` → providers above 20 ms.
2. `devtoolbox-container-bindings` with `filter` set to the slow provider's namespace → what it registers.
3. Suggest deferring the provider or moving heavy work out of `boot()`.

## Reading results

Responses are JSON. A `_truncated` key means the result was cut to fit your context: re-run with narrower options (the key lists them) instead of asking for the full dump.
```

- [ ] **Step 5: Run the test and Pint**

Run: `vendor/bin/pest tests/Unit/Mcp/BoostResourcesTest.php` — expected 2 passed.
Run: `vendor/bin/pint --test` (Pint ignores `.md`; the blade file has no PHP so nothing to format).

- [ ] **Step 6: Commit**

```bash
git add resources/boost tests/Unit/Mcp/BoostResourcesTest.php
git commit -m "feat: Laravel Boost guidelines and skill for DevToolbox"
```

---

### Task 10: CI job without laravel/mcp, README, CHANGELOG, PR and release

**Files:**
- Modify: `.github/workflows/tests.yml`, `README.md`, `CHANGELOG.md`

- [ ] **Step 1: Add the CI job**

In `.github/workflows/tests.yml`, add a second job after `test:` (same indentation level):

```yaml
  test-without-mcp:
    runs-on: ubuntu-latest
    name: Without laravel/mcp (PHP 8.4 - Laravel 13.*)

    steps:
      - name: Checkout code
        uses: actions/checkout@v5

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.4
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, bcmath, intl
          coverage: none

      - name: Install dependencies without laravel/mcp
        run: |
          composer remove laravel/mcp --dev --no-update --no-interaction
          composer require "laravel/framework:13.*" "orchestra/testbench:11.*" --no-interaction --no-update
          composer update --prefer-stable --prefer-dist --no-interaction

      - name: Execute tests
        run: vendor/bin/pest --exclude-group mcp
```

Reproduce locally before pushing:
```bash
cp composer.json /tmp/devtoolbox-composer.json.bak
composer remove laravel/mcp --dev --no-interaction
vendor/bin/pest --exclude-group mcp | tail -3
cp /tmp/devtoolbox-composer.json.bak composer.json && rm /tmp/devtoolbox-composer.json.bak
composer update --no-interaction
git diff --stat composer.json   # must be empty
```
Expected: all non-mcp tests pass without the package (this proves `src/Mcp/*` is never loaded on the normal path).

- [ ] **Step 2: README section**

Add a `## MCP Server (AI agents)` section to `README.md` after the commands section, containing: one paragraph (what it is), install (`composer require laravel/mcp --dev`), registration for Claude Code (`claude mcp add devtoolbox php artisan mcp:start devtoolbox`) and the generic `.mcp.json` snippet:

```json
{
  "mcpServers": {
    "devtoolbox": { "command": "php", "args": ["artisan", "mcp:start", "devtoolbox"] }
  }
}
```
the tool list (same 16 names, one line each, active ones flagged), the environment guard (`devtoolbox.mcp.environments`, `DEVTOOLBOX_MCP_ENABLED`), the `_truncated` note, and a `### Laravel Boost` sub-section (guidelines + skill installed by `php artisan boost:install`). Update the features list at the top of the README with one bullet.

- [ ] **Step 3: CHANGELOG**

Under `## [Unreleased]` in `CHANGELOG.md`, rename the section to `## [v1.7.0] - <date of the release, YYYY-MM-DD>` and make sure it contains, above the existing "Changed" items:

```markdown
### Added
- MCP server (`php artisan mcp:start devtoolbox`) exposing every scanner as a tool when `laravel/mcp` is installed. Registered only in `local`/`testing` (configurable via `devtoolbox.mcp`).
- `AbstractScanner::getOptionSchema()`: typed option declarations, used to build MCP input schemas; `getAvailableOptions()` is now derived from it.
- Laravel Boost guidelines (`resources/boost/guidelines/core.blade.php`) and the `devtoolbox-analysis` skill.
- MCP status in `dev:about+`.
```
Add a fresh empty `## [Unreleased]` above it.

- [ ] **Step 4: Full verification**

```bash
vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress && vendor/bin/pest | tail -3
```
Expected: all green; total tests ≥ 125 + the new ones.

- [ ] **Step 5: Commit, PR, merge, tag**

```bash
git add .github/workflows/tests.yml README.md CHANGELOG.md
git commit -m "docs: MCP server documentation, changelog and CI job without laravel/mcp"
git push -u origin feature/mcp-server
gh pr create --title "feat: MCP server exposing DevToolbox scanners" --body "Adds a local MCP server (laravel/mcp, optional dependency) exposing every scanner as a typed tool, guarded to local/testing environments, plus Laravel Boost guidelines and a skill. See docs/superpowers/specs/2026-09-17-devtoolbox-mcp-design.md."
gh pr checks --watch
```
When all checks are green: `gh pr merge --merge --delete-branch`, then `git checkout main && git pull --ff-only`, then **only after the merge**:
```bash
git tag -a v1.7.0 -m "v1.7.0"
git push origin v1.7.0
gh release create v1.7.0 --title "v1.7.0" --notes-file <(sed -n '/## \[v1.7.0\]/,/## \[v1.6.0\]/p' CHANGELOG.md | sed '$d')
```
Never move or delete a pushed tag; if something is wrong after tagging, publish v1.7.1.

---

## Self-review

- **Spec coverage**: §3 architecture → Tasks 6–7; §3.2 registration → Task 7; §3.3 version/boot guard → Task 7; §4 schema + 16 scanners + inferrer + compiler → Tasks 2–4; §5 truncation/errors/empty result → Tasks 5–6; §6 Boost → Task 9; §7 config/README/CHANGELOG/about+ → Tasks 1, 8, 10; §8 tests incl. job without laravel/mcp → Tasks 1–10.
- **Placeholders**: none; every step has code or an exact command. Two "verify in vendor" notes (Response accessors, `$version` property name, `TestListResponse` assertions) are explicit checks, not deferred work.
- **Type consistency**: `getOptionSchema()` shape identical in Tasks 2, 3, 4, 6; `ToolFactory::make(ScannerRegistry)` used identically in Tasks 6, 7; `McpRegistration::isEnvironmentAllowed(Application)` used in Tasks 7, 8; `ResponseTruncator::__construct(int)` bound in Task 7 and constructed directly in tests.
