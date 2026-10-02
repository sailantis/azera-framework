# Azera Framework Documentation

Guides are organized by topic. Start with **Getting Started**, or jump to the
area you need.

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

## Additional Components

- [Events](13-EVENTS.md) — PSR-14 dispatching and database events.
- [Cache](14-CACHE.md) — PSR-16 caching and built-in implementations.
- [Queues](15-QUEUES.md) — Synchronous jobs and async queue contracts.
- [AOP](16-AOP.md) — Method interceptors and built-in advice attributes.
- [Configuration](17-CONFIG.md) — Dot-notation access and environment overlays.
- [Enterprise Security](18-SECURITY-ENTERPRISE.md) — CSRF, rate limiting,
  password hashing, and authentication contracts.

## Reference

- [Cookbook](11-COOKBOOK.md) — Practical recipes for pagination, soft
  delete, transactions, subqueries, and more.
- [API Documentation](api/) — Auto-generated reference for every public
  class in the framework.

## Benchmarks

Azera is measured against Laravel, Symfony, Spiral, CodeIgniter 4, and
CakePHP 5 on the same full-stack workload. Summaries cover both deployment
models:

- [RoadRunner](19-BENCHMARKS-SUMMARY-ROADRUNNER.md) — a resident worker
  serves requests after one boot.
- [PHP-FPM](19-BENCHMARKS-SUMMARY-FPM.md) — the framework boots for each
  request.

The [complete report](https://sailantis.github.io/azera-competition/benchmarks/)
includes per-endpoint results, feature charts, and memory data. The summary
numbers come directly from benchmark result JSON.
