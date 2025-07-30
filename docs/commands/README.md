# Commands Reference

Laravel Devtoolbox provides a comprehensive set of artisan commands for analyzing and inspecting your Laravel application.

## General Commands

### `dev:scan`

The main scanning command that can analyze multiple aspects of your application.

```bash
php artisan dev:scan [type] [--all] [--format=FORMAT] [--output=FILE]
```

**Arguments:**
- `type` - Specific scanner type (models, routes, commands, services, middleware, views)

**Options:**
- `--all` - Scan all available types
- `--format=FORMAT` - Output format (array, json, count) 
- `--output=FILE` - Save output to file

**Examples:**
```bash
# List available scanner types
php artisan dev:scan

# Scan all types
php artisan dev:scan --all

# Scan specific type
php artisan dev:scan models

# Save scan results
php artisan dev:scan routes --format=json --output=routes.json
```

**Available Scanner Types:**
- `models` - Eloquent models and relationships
- `routes` - Application routes
- `commands` - Artisan commands
- `services` - Service container bindings
- `middleware` - Middleware classes and usage
- `views` - Blade templates and views
- `model-usage` - Model usage analysis
- `sql-trace` - SQL query tracing

### `dev:about+`

Enhanced version of Laravel's `about` command with additional environment and application details.

```bash
php artisan dev:about+ [--extended] [--performance] [--security] [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--extended` - Show extended information including detailed environment
- `--performance` - Include performance metrics and optimization tips
- `--security` - Include security-related information
- `--format=FORMAT` - Output format (table, json)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# Basic enhanced about information
php artisan dev:about+

# Extended information with performance metrics
php artisan dev:about+ --extended --performance

# Include security analysis
php artisan dev:about+ --extended --performance --security

# Export to JSON
php artisan dev:about+ --extended --format=json --output=about.json
```

---

## Model Commands

### `dev:models`

Scan and list all Eloquent models in your application.

```bash
php artisan dev:models [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--format=FORMAT` - Output format (table, json)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# List all models
php artisan dev:models

# Export models to JSON
php artisan dev:models --format=json --output=models.json
```

### `dev:model:where-used`

Find where a specific model is used throughout your application.

```bash
php artisan dev:model:where-used MODEL [--format=FORMAT] [--output=FILE]
```

**Arguments:**
- `MODEL` - The model class name or path

**Options:**
- `--format=FORMAT` - Output format (array, json, count)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# Find where User model is used
php artisan dev:model:where-used App\Models\User

# Find usage by path
php artisan dev:model:where-used app/Models/Post.php

# Export results
php artisan dev:model:where-used User --format=json --output=user-usage.json
```

### `dev:model:graph`

Generate a graph showing model relationships.

```bash
php artisan dev:model:graph [--format=FORMAT] [--output=FILE] [--direction=DIR]
```

**Options:**
- `--format=FORMAT` - Output format (mermaid, json)
- `--output=FILE` - Save output to file
- `--direction=DIR` - Graph direction (TB, BT, LR, RL)

**Examples:**
```bash
# Generate Mermaid diagram
php artisan dev:model:graph --format=mermaid

# Save diagram to file
php artisan dev:model:graph --format=mermaid --output=models.mmd

# Left-to-right layout
php artisan dev:model:graph --format=mermaid --direction=LR
```

---

## Route Commands

### `dev:routes`

Scan and analyze application routes.

```bash
php artisan dev:routes [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--format=FORMAT` - Output format (array, json, count)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# List all routes
php artisan dev:routes

# Export routes data
php artisan dev:routes --format=json --output=routes.json
```

### `dev:routes:unused`

Detect potentially unused routes in your application.

```bash
php artisan dev:routes:unused [--format=FORMAT] [--output=FILE] [--strict] [--exclude-api] [--security-focused]
```

**Options:**
- `--format=FORMAT` - Output format (table, json)
- `--output=FILE` - Save output to file
- `--strict` - Use strict detection (flags unprotected API routes too)
- `--exclude-api` - Exclude API routes from detection entirely
- `--security-focused` - Focus on security issues (dangerous unprotected routes)

**Examples:**
```bash
# Find unused routes (default mode)
php artisan dev:routes:unused

# Exclude API routes from detection
php artisan dev:routes:unused --exclude-api

# Focus on security issues only
php artisan dev:routes:unused --security-focused

# Use strict detection (flags all unprotected dangerous routes)
php artisan dev:routes:unused --strict

# Save analysis to file
php artisan dev:routes:unused --format=json --output=unused-routes.json
```

**Detection Logic:**
This command uses several heuristics to detect potentially unused or problematic routes:

1. **Obvious unused patterns**: Routes containing keywords like "unused", "legacy", "test", "demo", "sample"
2. **Unprotected debug routes**: Debug routes without authentication middleware
3. **Unprotected admin routes**: Admin/dashboard/settings routes without authentication
4. **Dangerous unprotected routes**: POST/PUT/PATCH/DELETE routes without CSRF or auth protection
5. **Static closure routes**: Unnamed routes returning static content with suspicious patterns

**Detection Modes:**
- **Default**: Flags obvious unused routes and security issues, includes dangerous API routes
- **Strict**: Additionally flags all unprotected API routes with dangerous HTTP methods
- **Security-focused**: Only reports routes with security implications
- **Exclude API**: Completely ignores API routes (useful for apps with separate API consumers)

### `dev:routes:where`

Find routes that use a specific controller or method (reverse route lookup).

```bash
php artisan dev:routes:where TARGET [--show-methods] [--include-parameters] [--format=FORMAT] [--output=FILE]
```

**Arguments:**
- `TARGET` - Controller class or method to search for (e.g., UserController or UserController@show)

**Options:**
- `--show-methods` - Show available methods in the target controller
- `--include-parameters` - Include route parameters in results
- `--format=FORMAT` - Output format (table, json)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# Find routes using UserController
php artisan dev:routes:where UserController

# Find specific method usage
php artisan dev:routes:where UserController@show

# Show available methods
php artisan dev:routes:where UserController --show-methods

# Include route parameters
php artisan dev:routes:where UserController --include-parameters

# Export results
php artisan dev:routes:where UserController --format=json --output=controller-routes.json
```

---

## Service Commands

### `dev:services`

Examine service container bindings and registrations.

```bash
php artisan dev:services [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--format=FORMAT` - Output format (array, json, count)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# List all services
php artisan dev:services

# Export service bindings
php artisan dev:services --format=json --output=services.json
```

### `dev:commands`

List and analyze artisan commands.

```bash
php artisan dev:commands [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--format=FORMAT` - Output format (array, json, count)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# List all commands
php artisan dev:commands

# Export commands data
php artisan dev:commands --format=json
```

### `dev:container:bindings`

Analyze Laravel container bindings, singletons, and dependency injection mappings.

```bash
php artisan dev:container:bindings [--filter=FILTER] [--show-resolved] [--show-parameters] [--show-aliases] [--group-by=TYPE] [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--filter=FILTER` - Filter bindings by name, namespace, or type
- `--show-resolved` - Attempt to resolve bindings and show actual instances
- `--show-parameters` - Show constructor parameters for classes
- `--show-aliases` - Include container aliases in output
- `--group-by=TYPE` - Group results by (type, namespace, singleton)
- `--format=FORMAT` - Output format (table, json)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# List all container bindings
php artisan dev:container:bindings

# Show resolved instances
php artisan dev:container:bindings --show-resolved

# Filter by namespace
php artisan dev:container:bindings --filter="App\Services"

# Show constructor parameters
php artisan dev:container:bindings --show-parameters

# Group by singleton status
php artisan dev:container:bindings --group-by=singleton

# Export analysis
php artisan dev:container:bindings --format=json --output=container-bindings.json
```

### `dev:providers:timeline`

Analyze service provider boot timeline and performance.

```bash
php artisan dev:providers:timeline [--slow-threshold=MS] [--include-deferred] [--show-dependencies] [--show-bindings] [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--slow-threshold=MS` - Threshold in milliseconds to mark providers as slow (default: 50)
- `--include-deferred` - Include deferred providers in analysis
- `--show-dependencies` - Show provider dependencies and load order
- `--show-bindings` - Show services registered by each provider
- `--format=FORMAT` - Output format (table, json)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# Analyze provider timeline
php artisan dev:providers:timeline

# Find slow providers (>100ms)
php artisan dev:providers:timeline --slow-threshold=100

# Include deferred providers
php artisan dev:providers:timeline --include-deferred

# Show dependencies and bindings
php artisan dev:providers:timeline --show-dependencies --show-bindings

# Export analysis
php artisan dev:providers:timeline --format=json --output=provider-timeline.json
```

---

## Middleware Commands

### `dev:middleware`

Analyze middleware classes and their usage.

```bash
php artisan dev:middleware [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--format=FORMAT` - Output format (array, json, count)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# List middleware
php artisan dev:middleware

# Export middleware analysis
php artisan dev:middleware --format=json --output=middleware.json
```

### `dev:middlewares:where-used`

Find where specific middleware is used throughout your application.

```bash
php artisan dev:middlewares:where-used MIDDLEWARE [--show-routes] [--show-groups] [--format=FORMAT] [--output=FILE]
```

**Arguments:**
- `MIDDLEWARE` - Middleware class name, alias, or path

**Options:**
- `--show-routes` - Show routes that use this middleware
- `--show-groups` - Show middleware groups that include this middleware
- `--format=FORMAT` - Output format (table, json)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# Find where auth middleware is used
php artisan dev:middlewares:where-used auth

# Find custom middleware usage
php artisan dev:middlewares:where-used App\Http\Middleware\CustomMiddleware

# Show routes and groups
php artisan dev:middlewares:where-used auth --show-routes --show-groups

# Export results
php artisan dev:middlewares:where-used auth --format=json --output=auth-usage.json
```

---

## View Commands

### `dev:views`

Scan and analyze Blade templates and views.

```bash
php artisan dev:views [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--format=FORMAT` - Output format (array, json, count)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# List all views
php artisan dev:views

# Export views data
php artisan dev:views --format=json --output=views.json
```

---

## Database Analysis Commands

### `dev:db:column-usage`

Analyze database column usage across the Laravel application codebase.

```bash
php artisan dev:db:column-usage [--table=TABLE] [--exclude=TABLE] [--unused-only] [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--table=TABLE` - Specific tables to analyze (multiple allowed)
- `--exclude=TABLE` - Tables to exclude from analysis (multiple allowed)
- `--unused-only` - Show only unused columns
- `--format=FORMAT` - Output format (table, json)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# Analyze all tables
php artisan dev:db:column-usage

# Analyze specific tables
php artisan dev:db:column-usage --table=users --table=posts

# Find unused columns only
php artisan dev:db:column-usage --unused-only

# Exclude system tables
php artisan dev:db:column-usage --exclude=migrations --exclude=sessions

# Export analysis results
php artisan dev:db:column-usage --format=json --output=column-usage.json
```

---

## Security Analysis Commands

### `dev:security:unprotected-routes`

Scan for routes that are not protected by authentication middleware.

```bash
php artisan dev:security:unprotected-routes [--critical-only] [--exclude=PATTERN] [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--critical-only` - Show only critical security issues
- `--exclude=PATTERN` - Route patterns to exclude from check (multiple allowed)
- `--format=FORMAT` - Output format (table, json)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# Scan all routes for security issues
php artisan dev:security:unprotected-routes

# Show only critical issues
php artisan dev:security:unprotected-routes --critical-only

# Exclude API routes from scan
php artisan dev:security:unprotected-routes --exclude="api/*"

# Exclude multiple patterns
php artisan dev:security:unprotected-routes --exclude="public/*" --exclude="webhooks/*"

# Export security analysis
php artisan dev:security:unprotected-routes --format=json --output=security-scan.json
```

---

## SQL Analysis Commands

### `dev:sql:trace`

Trace SQL queries executed during route execution.

```bash
php artisan dev:sql:trace [--route=ROUTE] [--url=URL] [--method=METHOD] [--parameters=JSON] [--headers=JSON] [--output=FILE]
```

**Options:**
- `--route=ROUTE` - Named route to trace
- `--url=URL` - URL path to trace
- `--method=METHOD` - HTTP method (default: GET)
- `--parameters=JSON` - Route/query parameters as JSON
- `--headers=JSON` - Request headers as JSON
- `--output=FILE` - Save output to file

**Examples:**
```bash
# Trace a named route
php artisan dev:sql:trace --route=users.index

# Trace a URL with POST method
php artisan dev:sql:trace --url=/api/users --method=POST

# Trace with parameters
php artisan dev:sql:trace --route=users.show --parameters='{"user": 1}'

# Trace with custom headers
php artisan dev:sql:trace --url=/api/users --headers='{"Authorization": "Bearer token"}'

# Save detailed trace
php artisan dev:sql:trace --route=dashboard --output=sql-trace.json
```

### `dev:sql:duplicates`

Analyze SQL queries for N+1 problems, duplicates, and performance issues.

```bash
php artisan dev:sql:duplicates [--route=ROUTE] [--url=URL] [--threshold=NUM] [--auto-explain] [--method=METHOD] [--parameters=JSON] [--headers=JSON] [--data=JSON] [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--route=ROUTE` - Specific route to analyze
- `--url=URL` - Specific URL to analyze
- `--threshold=NUM` - Duplicate query threshold (default: 2)
- `--auto-explain` - Run EXPLAIN on detected problematic queries
- `--method=METHOD` - HTTP method for the request (default: GET)
- `--parameters=JSON` - Route parameters as JSON
- `--headers=JSON` - Request headers as JSON
- `--data=JSON` - Request data as JSON
- `--format=FORMAT` - Output format (table, json)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# Analyze N+1 problems on a route
php artisan dev:sql:duplicates --route=users.index

# Analyze specific URL with custom threshold
php artisan dev:sql:duplicates --url=/api/users --threshold=3

# Auto-explain problematic queries
php artisan dev:sql:duplicates --route=dashboard --auto-explain

# Analyze POST request with data
php artisan dev:sql:duplicates --url=/api/orders --method=POST --data='{"status":"active"}'

# Export analysis
php artisan dev:sql:duplicates --route=users.index --format=json --output=n+1-analysis.json
```

---

## Environment Commands

### `dev:env:diff`

Compare environment configuration files.

```bash
php artisan dev:env:diff [--against=FILE] [--format=FORMAT] [--output=FILE]
```

**Options:**
- `--against=FILE` - File to compare against (default: .env.example)
- `--format=FORMAT` - Output format (array, json)
- `--output=FILE` - Save output to file

**Examples:**
```bash
# Compare .env with .env.example
php artisan dev:env:diff

# Compare with custom file
php artisan dev:env:diff --against=.env.staging

# Export differences
php artisan dev:env:diff --format=json --output=env-diff.json
```

---

## Logging Commands

### `dev:log:tail`

Monitor Laravel logs with real-time filtering and pattern matching.

```bash
php artisan dev:log:tail [--file=FILE] [--lines=NUM] [--pattern=PATTERN] [--level=LEVEL] [--follow] [--format=FORMAT]
```

**Options:**
- `--file=FILE` - Specific log file to tail (default: laravel.log)
- `--lines=NUM` - Number of lines to show initially (default: 50)
- `--pattern=PATTERN` - Filter logs by pattern (regex supported)
- `--level=LEVEL` - Filter by log level (emergency, alert, critical, error, warning, notice, info, debug)
- `--follow` - Follow log in real-time (like tail -f)
- `--format=FORMAT` - Output format (table, json)

**Examples:**
```bash
# Tail default log file
php artisan dev:log:tail

# Follow logs in real-time
php artisan dev:log:tail --follow

# Filter by error level
php artisan dev:log:tail --level=error

# Filter by pattern
php artisan dev:log:tail --pattern="database"

# Tail specific log file
php artisan dev:log:tail --file=custom.log

# Show last 100 lines
php artisan dev:log:tail --lines=100

# Combine filters
php artisan dev:log:tail --follow --level=error --pattern="SQL"
```

---

## Common Options

### Format Options

All commands support these format options:

- `array` / `table` - Human-readable console output (default)
- `json` - Structured JSON output
- `count` - Count-only output
- `mermaid` - Diagram syntax (specific commands only)

### Output Options

Save results to files:

```bash
# JSON output
--output=filename.json

# Mermaid diagrams
--output=diagram.mmd

# Custom paths
--output=/path/to/file.json
```

## Advanced Usage

### Combining Commands

```bash
# Full application analysis
php artisan dev:scan --all --format=json --output=full-analysis.json

# Model-focused analysis
php artisan dev:models --format=json --output=models.json
php artisan dev:model:graph --format=mermaid --output=relationships.mmd
```

### CI/CD Integration

```bash
# Check thresholds
UNUSED_ROUTES=$(php artisan dev:routes:unused --format=count | jq '.count')
if [ $UNUSED_ROUTES -gt 5 ]; then
    echo "Too many unused routes: $UNUSED_ROUTES"
    exit 1
fi
```

### Automated Reporting

```bash
#!/bin/bash
DATE=$(date +%Y%m%d)
mkdir -p reports/$DATE

php artisan dev:scan models --format=json --output=reports/$DATE/models.json
php artisan dev:scan routes --format=json --output=reports/$DATE/routes.json
php artisan dev:routes:unused --format=json --output=reports/$DATE/unused-routes.json
```

## Error Handling

Commands return appropriate exit codes:
- `0` - Success
- `1` - General error
- `2` - Invalid arguments

Example error handling in scripts:
```bash
if ! php artisan dev:model:where-used InvalidModel; then
    echo "Model analysis failed"
    exit 1
fi
```