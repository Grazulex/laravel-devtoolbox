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
