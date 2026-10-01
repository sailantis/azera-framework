# Azera Framework Documentation

The full Azera framework reference, organised by topic. Start with
**Getting Started** if this is your first time using Azera, or jump
straight into the area you need.

## Getting Started

- [Getting Started](00-GETTING-STARTED.md) — Install Azera, set up a
  project, write your first controller, model, view and CLI task.

## Core Concepts

- [Architecture](01-ARCHITECTURE.md) — How `AppContext`, the MVC layer,
  the database layer and the CLI layer fit together.
- [MVC Routing](02-CORE-ROUTING.md) — `Router`, named routes, parameter
  validation, route groups and middleware.

## Controllers & Views

- [Controllers & Views](03-CONTROLLERS-VIEWS.md) — Action methods,
  dependency injection, view rendering, layouts and partials.
- [Clarity Template Engine](03b-CLARITY-ENGINE.md) — The template engine
  Azera uses by default: `.clarity.html` syntax, filters, inheritance,
  extensions.

## Data Layer

- [Models & ORM](04-MODELS-ORM.md) — Active Record, queries, state
  tracking, read/write connections and `ModelMapping`.
- [Database Queries](05-DATABASE-QUERIES.md) — The unified query builder
  for SELECT, INSERT, UPDATE and DELETE.

## HTTP & Validation

- [HTTP Request](06-HTTP-REQUEST.md) — Accessing GET, POST, headers and
  uploaded files in a controller.
- [Validation](07-VALIDATION.md) — Fluent field rules, type coercion and
  error collection.

## Operations

- [CLI Tasks](08-CLI-TASKS.md) — Building `*Task` classes, option parsing
  and the `model-sync` built-in.
- [Security](09-SECURITY.md) — SQL-injection safety, CSRF, output escaping,
  password hashing, and encrypted cookies.
- [Logging](10-LOGGING.md) — Event-based logging hooks for the database
  and the application.

## Reference

- [Cookbook](11-COOKBOOK.md) — Practical recipes for pagination, soft
  delete, transactions, subqueries, and more.
- [API Documentation](api/) — Auto-generated reference for every public
  class in the framework.

## Benchmarks

Azera is measured against Laravel, Symfony, Spiral, CodeIgniter 4 and
CakePHP 5 on an identical full-stack workload — routing, controller, ORM
query (SQLite), template render, response — on a real server. Two
summaries are published, one per deployment model:

- [RoadRunner](19-BENCHMARKS-SUMMARY-ROADRUNNER.md) — a resident worker,
  where the framework boots once and serves every request.
- [PHP-FPM](19-BENCHMARKS-SUMMARY-FPM.md) — nginx + PHP-FPM, where the
  framework boots again for every request, as PHP usually runs in
  production.

The complete report — every framework's feature races, endpoint by
endpoint, with the resident-worker memory charts — is published at
<https://sailantis.github.io/azera-competition/benchmarks/>.

The numbers on these pages are generated from the benchmark's own
result JSON, never transcribed, so a chart and the table beside it
cannot disagree.
