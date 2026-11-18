# Framework Competition — PHP-FPM Summary

The headline numbers from the measured nginx + PHP-FPM deployment: the cost of one pass over every endpoint, the plain routing request, the two data-access races, and what one request costs in memory. End-to-end HTTP over loopback, so the constant webserver overhead is included (see floor-http/floor-php in the dataset). The worker-recycling setting of the pool is stated with the server floor below, because it changes what each row contains.

**Environment** — PHP 8.5.11 · Linux 6.8.0-139-generic · OPcache: yes · JIT: tracing (buffer 64M) · 1000 iterations per run over 10 runs, lower is better.

**Frameworks** — Azera v0.5.0 · Laravel 12.69.2 · Symfony 7.4.18 · Spiral 3.17.2 · CodeIgniter 4.7.4 · CakePHP 5.4.0.

_Measured 2026-11-18T16:24:17+00:00_

## Total response times

Total time to serve one pass over every benchmarked endpoint — each framework's sum of its endpoint medians, not a single response time — drawn relative to the baseline, so a row states how many times the baseline's own total it needed. Each endpoint's median is boot-inclusive occupancy for this view's deployment model, so the total is the worker time one pass over every endpoint costs. The chart orders the frameworks by that total and prints each one's multiplier beside its row.

![Total response times](images/benchmarks/summary-fpm/speedup.svg)

## Feature benchmarks

One race per framework feature, each run as a real request against a real database. Each feature states the request it measures under its own heading. Every figure — the winner of each race and the margin over the runner-up — is in that feature's own chart below, which anchors each endpoint at its fastest framework.

### Routing
 `GET /` — dispatches a request through the router and returns a rendered template — no database access. The `/echo` band runs the same path with a constant handler instead, so what the template render costs can be read off directly rather than inferred.

![Routing](images/benchmarks/summary-fpm/feature-routing.svg)

### ORM / Active Record
 `GET /items` — loads one page of the 1,000 seeded rows through each framework's ORM / Active Record layer: 20 items plus a COUNT for the pagination total. The `-fast` band repeats that page with hydration switched off, so the cost of building one object per row can be read directly instead of inferred.

![ORM / Active Record](images/benchmarks/summary-fpm/feature-orm.svg)

### REST API (JSON)
 `GET /api/items` — serves the same page of items as a JSON response rather than HTML, which adds serialization to the ORM work. The `-fast` band repeats that response with hydration switched off, so serialization cost and hydration cost can be told apart.

![REST API (JSON)](images/benchmarks/summary-fpm/feature-rest-api.svg)

## Per-request memory

How much memory a single request needs, for every framework, measured inside the FPM worker that served it. The numbers come from the FPM worker process itself: because the entry script is torn down when the request ends, it appends one sample as it exits, and the harness reads that back. The pool is `pm = static` with `max_children = 1` and `max_requests = 0`, so this is ONE worker that stays alive for the whole block — which is why it has retained memory worth reporting at all.

A request's high-water mark is taken from the framework-ready boundary of the entry script to the moment the response is finished, with the mark reset at that boundary — so it counts exactly what serving the request cost, and never bleeds into the next one. Each endpoint was probed once, so the range shows how much the endpoints themselves differ — a property of the workload rather than of the measurement.

All six frameworks are drawn on one shared MB axis. The **left cap** is the lightest probed endpoint, the **dot** is the median endpoint, and the **right cap** is the heaviest. Every mark is a measured endpoint rather than an interpolation, so each can be named — the three numbers printed beside each bar are those same three readings. The faint bar behind each mark runs from zero to the median, so a row's length is read against the axis rather than estimated from the caps. The multiplier beside a row divides its median by the lightest median on the page; the reference row carries none.

Rows are ordered by the **median** request — a framework's typical cost — so one heavy route cannot reorder the table on its own. A row that stays flat and a row that reaches far right therefore say different things: the first is cheap on every route, the second is cheap on a typical request until one heavy route sets the worst case a pool has to be sized for.

![Per-request memory](images/benchmarks/summary-fpm/resident-memory.svg)

## Latency by endpoint

Trimmed mean in milliseconds, lower is better. **Bold** = fastest for that endpoint. Every number is END-TO-END per-request occupancy for the view's deployment model: the framework boot of that model is part of the cell, not parked in a separate chart. These are REAL deployments measured over HTTP: every row carries the constant server cost, which is why the values cluster — the floor note below states what stands under them. The workload column states what each request reads or writes. Every framework runs the same seeded database and the same page size, so the payload is identical no matter which framework served it; the workload column is the part of the suite that varies.

**Server floor** — measured nginx + PHP-FPM with `pm.max_requests=0`: the pool never recycles its worker, so no process is spawned per request — what remains is the FastCGI handshake plus a minimal script. A hello-world endpoint that boots nothing but PHP (`floor-php`) and a static file through nginx (`floor-http`) measure exactly that cost — the floor every row below stands on. Subtracting that floor leaves the framework's own per-request boot, which FPM still pays for every request even though its worker survives.

| Request | Workload | Azera | Laravel | Symfony | Spiral | CodeIgniter | CakePHP |
|---|---|---:|---:|---:|---:|---:|---:|
| `GET /` | no DB — routing + template only | **0.641** | 3.58 | 1.86 | 6.66 | 1.92 | 1.45 |
| `GET /echo` | no DB — routing + dispatch only | **0.536** | 3.44 | 1.64 | 6.58 | 1.73 | 1.33 |
| `GET /items` | 20 of 1000 items (page 1, + COUNT) | **1.37** | 4.69 | 3.39 | 7.78 | 2.73 | 2.82 |
| `GET /items-fast` | 20 of 1000 items (page 1, + COUNT), hydrated rows not built | **1.29** | 4.41 | 3.31 | 7.61 | 2.61 | 2.74 |
| `GET /items/1` | 1 item by id | **1.27** | 4.41 | 2.76 | 7.61 | 2.56 | 2.58 |
| `POST /items` | 1 row upserted (sentinel #999999) | **1.44** | 4.42 | 3.68 | 7.71 | 2.78 | 2.77 |
| `GET /items-qb` | 20 of 1000 items (page 1, + COUNT) | **1.21** | 4.27 | 2.43 | 7.27 | 2.63 | 2.23 |
| `GET /items-qb/1` | 1 item by id | **1.17** | 4.13 | 2.37 | 7.23 | 2.53 | 2.15 |
| `POST /items-qb` | 1 row upserted (sentinel #999997) | **1.27** | 4.25 | 3.20 | 7.42 | 2.76 | 2.24 |
| `GET /api/items` | 20 of 1000 items as JSON | **1.25** | 4.65 | 2.73 | 6.88 | 2.54 | 2.49 |
| `GET /api/items-fast` | 20 of 1000 items as JSON, rows not hydrated | **1.16** | 4.24 | 2.68 | 6.67 | 2.45 | 2.44 |
| `GET /api/items/1` | 1 item by id as JSON | **1.19** | 4.42 | 2.50 | 6.78 | 2.43 | 2.45 |
| `POST /api/items` | 1 row upserted (sentinel #999998) | **1.24** | 4.38 | 3.46 | 6.85 | 2.55 | 2.61 |
| `GET /features/aop` | no DB — interceptor pipeline | **2.38** | 4.95 | 3.06 | 8.34 | — | — |
| `GET /features/cache` | COUNT(*) of 1000 rows, cached 10s (miss = query) | **1.45** | 4.32 | 2.71 | 7.43 | 2.40 | 2.33 |
| `GET /features/log` | no DB — buffered log handlers | **1.05** | 3.50 | 1.74 | 6.80 | — | — |
| `GET /features/retry` | no DB — retry policy | **1.03** | 3.51 | 1.74 | 6.82 | — | — |
| `GET /features/pipeline` | no DB — middleware pipeline | **0.594** | 3.51 | 1.75 | 6.79 | — | — |
| `GET /features/db-events` | 1 event row INSERTed per request | **1.53** | 4.33 | 2.86 | 7.61 | 2.66 | 2.53 |
| `GET /features/events` | no DB — in-process listeners | **1.51** | 4.01 | 2.20 | 7.37 | 2.60 | 1.88 |
| `GET /features/validation` | no DB — validator run | **0.609** | 4.41 | 2.07 | 6.93 | 2.20 | 1.73 |
| `GET /features/config` | no DB — config lookup | **0.557** | 3.48 | 1.73 | 6.84 | 1.86 | 1.33 |
| `GET /features/request-scoped` | no DB — scoped service resolve | **0.560** | 3.46 | 1.73 | 6.91 | 1.84 | 1.34 |
| `GET /features/rate-limit` | no DB — cache-backed limiter | **0.574** | 3.64 | 1.77 | 6.90 | 1.87 | 1.47 |

**Full comparison** — this page is a summary. The complete report, with every feature chart and the endpoint table for all six frameworks, is published at:

- The complete report (all six frameworks, every feature chart) — <https://sailantis.github.io/azera-competition/benchmarks/>
- This model, measured end to end — <https://sailantis.github.io/azera-competition/benchmarks/view-real-fpm.html>
- The same work as a resident worker (Volucer) — <https://sailantis.github.io/azera-competition/benchmarks/view-summary-volucer.html>

---

> **Auto-generated.** This page and its charts are produced by the `azera-competition` repository:
> `php run.php --apps=azera,laravel,symfony,spiral,codeigniter,cakephp --warm --cold --seed --out=results/free-for-all-opcache-iso`
> then `php scripts/derive-fpm.php results/free-for-all-opcache-iso` and `php scripts/report.php --publish=framework`. Do not edit by hand — re-run the benchmark to update it.

Every chart is a plain SVG generated from the result JSON, so the numbers and the diagrams can never disagree.
