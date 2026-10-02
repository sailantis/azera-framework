# Framework Competition — PHP-FPM Summary

This summary covers nginx + PHP-FPM. Requests were measured over HTTP
loopback, so the results include server overhead; the server floor below
shows that cost.

**Environment** — PHP 8.3.33 · Linux 6.8.0-139-generic · OPcache: yes · 1000 iterations per run over 10 runs, lower is better.

**Frameworks** — Azera dev-main (v0.1.0) · Laravel 12.69.2 · Symfony 7.4.18 · Spiral 3.17.2 · CodeIgniter 4.7.4 · CakePHP 5.4.0.

_Measured 2026-09-29T12:55:05+00:00_

## Total response times

Each total is the sum of a framework's median times across all endpoints, not
a single request time. The chart compares totals with the baseline; PHP-FPM
endpoint medians include framework boot.

![Total response times](images/benchmarks/summary-fpm/speedup.svg)

## Feature benchmarks

Each chart compares a real request for one feature against a live database.
The heading names the endpoint and workload.

### Routing
 `GET /` — dispatches a plain request through the router and returns a rendered template — no database access.

![Routing](images/benchmarks/summary-fpm/feature-routing.svg)

### ORM / Active Record
 `GET /items` — loads one page of the 1,000 seeded rows through each framework's ORM / Active Record layer: 20 items plus a COUNT for the pagination total.

![ORM / Active Record](images/benchmarks/summary-fpm/feature-orm.svg)

### REST API (JSON)
 `GET /api/items` — serves the same page of items as a JSON response rather than HTML, which adds serialization to the ORM work.

![REST API (JSON)](images/benchmarks/summary-fpm/feature-rest-api.svg)

## Per-request memory

Memory is measured inside one PHP-FPM worker that stays alive for the run
(`pm = static`, `max_children = 1`, `max_requests = 0`). Each request records a
sample as its entry script exits, and the harness reads it from the worker.

The high-water mark resets when the framework is ready and is sampled when the
response finishes. Each endpoint was probed once, so the range reflects workload
differences rather than repeated measurements.

All frameworks share one MB axis. The **left cap**, **dot**, and **right cap**
show the lightest, median, and heaviest endpoint. Each mark is a measurement;
the labels give its value. The faint bar runs from zero to the median. The
multiplier compares each median with the lightest one.

Rows are ordered by median memory, showing typical usage. A wide range signals
that a heavy route may affect pool sizing.

![Per-request memory](images/benchmarks/summary-fpm/resident-memory.svg)

## Latency by endpoint

Trimmed mean per request in milliseconds; lower is better and **bold** marks
the fastest result. PHP-FPM timings include framework boot and HTTP server
overhead. All frameworks use the same seeded database and page size; the
workload column shows what varies.

**Server floor** — the pool does not recycle workers (`pm.max_requests=0`).
`floor-php` measures a minimal PHP request; `floor-http` measures a static
file through nginx. Together they show the server overhead in each result.
Subtracting that overhead estimates framework boot, which PHP-FPM pays on
every request.

| Request | Workload | Azera | Laravel | Symfony | Spiral | CodeIgniter | CakePHP |
|---|---|---:|---:|---:|---:|---:|---:|
| `GET /` | no DB — routing + template only | **0.807** | 4.50 | 2.06 | 7.87 | 1.99 | 1.50 |
| `GET /items` | 20 of 1000 items (page 1, + COUNT) | **1.57** | 5.74 | 3.73 | 8.93 | 2.70 | 2.86 |
| `GET /items/1` | 1 item by id | **1.44** | 5.40 | 3.00 | 8.72 | 2.60 | 2.66 |
| `POST /items` | 1 row upserted (sentinel #999999) | **1.65** | 5.46 | 3.92 | 8.94 | 2.70 | 2.80 |
| `GET /items-qb` | 20 of 1000 items (page 1, + COUNT) | **1.38** | 5.31 | 2.63 | 8.37 | 2.68 | 2.27 |
| `GET /items-qb/1` | 1 item by id | **1.34** | 5.12 | 2.56 | 8.37 | 2.57 | 2.19 |
| `POST /items-qb` | 1 row upserted (sentinel #999997) | **1.44** | 5.18 | 3.39 | 8.53 | 2.81 | 2.30 |
| `GET /api/items` | 20 of 1000 items as JSON | **1.36** | 6.09 | 3.00 | 7.91 | 2.55 | 2.55 |
| `GET /api/items/1` | 1 item by id as JSON | **1.39** | 5.88 | 2.75 | 7.78 | 2.52 | 2.51 |
| `POST /api/items` | 1 row upserted (sentinel #999998) | **1.47** | 5.31 | 3.62 | 7.87 | 2.63 | 2.65 |
| `GET /features/aop` | no DB — interceptor pipeline | **2.52** | 6.03 | 3.20 | 9.48 | — | — |
| `GET /features/cache` | COUNT(*) of 1000 rows, cached 10s (miss = query) | **1.64** | 5.35 | 2.96 | 8.66 | 2.50 | 2.39 |
| `GET /features/log` | no DB — buffered log handlers | **1.22** | 4.51 | 1.97 | 7.97 | — | — |
| `GET /features/retry` | no DB — retry policy | **1.23** | 4.51 | 1.95 | 8.01 | — | — |
| `GET /features/pipeline` | no DB — middleware pipeline | **0.796** | 4.52 | 1.94 | 8.03 | — | — |
| `GET /features/db-events` | 1 event row INSERTed per request | **1.74** | 5.38 | 3.12 | 8.84 | 2.72 | 2.60 |
| `GET /features/events` | no DB — in-process listeners | **1.72** | 5.12 | 2.39 | 8.64 | 2.68 | 1.93 |
| `GET /features/validation` | no DB — validator run | **0.797** | 5.55 | 2.28 | 8.11 | 2.27 | 1.80 |
| `GET /features/config` | no DB — config lookup | **0.757** | 4.52 | 1.94 | 7.96 | 1.93 | 1.36 |
| `GET /features/request-scoped` | no DB — scoped service resolve | **0.774** | 4.53 | 1.93 | 8.00 | 1.91 | 1.38 |
| `GET /features/rate-limit` | no DB — cache-backed limiter | **0.777** | 4.71 | 1.98 | 8.12 | 1.93 | 1.51 |

**Full comparison** — this page is a summary. The complete report, with every feature chart and the endpoint table for all six frameworks, is published at:

- The complete report (all six frameworks, every feature chart) — <https://sailantis.github.io/azera-competition/benchmarks/>
- This model, measured end to end — <https://sailantis.github.io/azera-competition/benchmarks/view-real-fpm.html>
- The RoadRunner summary — <https://sailantis.github.io/azera-competition/benchmarks/view-summary-roadrunner.html>

---

> **Auto-generated.** This page and its charts are produced by the `azera-competition` repository:
> `php run.php --apps=azera,laravel,symfony,spiral,codeigniter,cakephp --warm --cold --seed --out=results/free-for-all-opcache-iso`
> then `php scripts/derive-fpm.php results/free-for-all-opcache-iso` and `php scripts/report.php --publish=framework`. Do not edit by hand — re-run the benchmark to update it.

Every chart is a plain SVG generated from the result JSON, so the numbers and the diagrams can never disagree.
