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
