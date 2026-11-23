# Framework Competition — Volucer Summary

The headline numbers from the measured Volucer deployment. Volucer runs the app as a resident worker under a supervisor the deployment owns: the framework boots ONCE, before the supervisor forks, and every request is served from that already-built state. End-to-end HTTP over loopback, so the constant webserver overhead is included.

**Environment** — PHP 8.5.11 · Linux 6.8.0-139-generic · JIT: tracing (buffer 64M) · 1000 iterations per run over 10 runs, lower is better.

**Frameworks** — Azera v0.5.1-3-g978135e · Laravel 12.69.2 · Symfony 7.4.18 · Spiral 3.17.2 · CodeIgniter 4.7.4 · CakePHP 5.4.0 · Phalcon 6 (RC) 6.0.0RC4.

_Measured 2026-11-23T16:58:40+00:00_

## Total response times

Total time to serve one pass over every benchmarked endpoint — each framework's sum of its endpoint medians, not a single response time — drawn relative to the baseline, so a row states how many times the baseline's own total it needed. Each endpoint's median is boot-inclusive occupancy for this view's deployment model, so the total is the worker time one pass over every endpoint costs. The chart orders the frameworks by that total and prints each one's multiplier beside its row.

![Total response times](images/benchmarks/summary-volucer/speedup.svg)

## Feature benchmarks

One race per framework feature, each run as a real request against a real database. Each feature states the request it measures under its own heading. Every figure — the winner of each race and the margin over the runner-up — is in that feature's own chart below, which anchors each endpoint at its fastest framework.

### Routing
 `GET /api/routing/1` — matches a typed route and dispatches it to a constant handler — no template and no database access — so the band measures route matching and dispatch and nothing else. The endpoint sits in a deep bucket of the route table, which is where a compiled index actually saves work. `/echo` is drawn beside it: the same constant handler behind a STATIC route, i.e. the floor that route matching is measured against.

![Routing](images/benchmarks/summary-volucer/feature-routing.svg)

### ORM / Active Record
 `GET /items` — loads one page of the 1,000 seeded rows through each framework's ORM / Active Record layer: 20 items plus a COUNT for the pagination total. The `-fast` band repeats that page with hydration switched off, so the cost of building one object per row can be read directly instead of inferred.

![ORM / Active Record](images/benchmarks/summary-volucer/feature-orm.svg)

### REST API (JSON)
 `GET /api/items` — serves the same page of items as a JSON response rather than HTML, which adds serialization to the ORM work. The `-fast` band repeats that response with hydration switched off, so serialization cost and hydration cost can be told apart.

![REST API (JSON)](images/benchmarks/summary-volucer/feature-rest-api.svg)

## Resident worker memory

Read from inside the live worker after each endpoint, on an extra untimed request that never touches the latency numbers. All six frameworks are drawn on one shared MB axis. The **left cap** is the PHP heap with the application booted and **no request served** — the framework's own data structures, with opcache bytecode excluded because it lives in shared memory. The **dot** is the heap after the last endpoint, and the **right cap** is the largest heap any endpoint reached. A narrow-left range that reaches far right is the shape worth watching: cheap to exist, expensive at its worst.

The numbers are read from the resident Volucer attach worker, which answers them directly in response headers.

Rows are ordered by the **dot** — the heap the worker was left holding after its last endpoint — so the table reads as one ranking from lightest steady state to heaviest. The multiplier beside a row divides its dot by the lightest dot on the page; the reference row carries none. A row can therefore sit high while having the lightest left cap: that is a framework that is cheap to boot and expensive to keep running, which is exactly the distinction the three marks exist to draw.

The dot and the right cap are both endpoint-order dependent — the probe reads the whole heap once per endpoint, so it cannot say what one request costs on its own — which is why they are drawn as a range and the dot marks the end of the run rather than a lighter reading. That caveat bounds what the numbers MEAN; it does not invalidate the comparison, because every row is read from the same single sequence of endpoints and therefore at the same moment. What it rules out is reading any one of them as a per-request cost. The distance from the left cap to the right one, and the number of steps over which the heap rises, are the growth a long-lived worker accumulates.

![Resident worker memory](images/benchmarks/summary-volucer/resident-memory.svg)

## Latency by endpoint

Trimmed mean in milliseconds, lower is better. **Bold** = fastest for that endpoint. Every number is END-TO-END per-request occupancy for the view's deployment model: the framework boot of that model is part of the cell, not parked in a separate chart. These are REAL deployments measured over HTTP: every row carries the constant server cost, which is why the values cluster — the floor note below states what stands under them. The workload column states what each request reads or writes. Every framework runs the same seeded database and the same page size, so the payload is identical no matter which framework served it; the workload column is the part of the suite that varies.

These rows are end-to-end for a resident worker with a pool that never recycles it (one long-lived worker): the worker booted once before the first request and serves the whole run, so a cell is the measured request itself and carries no boot. The one-time recycle cost is measured in the full report linked at the foot of this page — read that when sizing a pool that is recycled or restarted, or when requests queue behind one worker.

**Server floor** — none measured for this server: the resident floor in this dataset (`floor-rr`) was measured through RoadRunner, whose process model differs from Volucer attach's, so its number is not this server's overhead and does not appear in these rows. Read the figures here as end-to-end; differences larger than the floor in the other resident summaries are framework differences.

| Request | Workload | Azera | Laravel | Symfony | Spiral | CodeIgniter | CakePHP | Phalcon 6 (RC) |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| `GET /api/routing/1` | no DB — typed route + constant body (routing only) | **0.168** | 0.408 | 0.253 | 0.650 | 0.526 | 0.282 | 0.169 |
| `GET /` | no DB — routing + template only | 0.197 | 0.383 | 0.257 | 0.668 | 0.668 | 0.307 | **0.174** |
| `GET /echo` | no DB — static route, constant body (throughput floor) | 0.176 | 0.356 | 0.232 | 0.593 | 0.565 | 0.257 | **0.149** |
| `GET /items` | 20 of 1000 items (page 1, + COUNT) | **0.334** | 0.934 | 0.851 | 0.944 | 1.08 | 0.750 | 0.578 |
| `GET /items-fast` | 20 of 1000 items (page 1, + COUNT), hydrated rows not built | **0.287** | 0.737 | 0.811 | 0.873 | 0.991 | 0.694 | 0.569 |
| `GET /items/1` | 1 item by id | **0.235** | 0.700 | 0.507 | 0.837 | 0.926 | 0.590 | 0.405 |
| `POST /items` | 1 row upserted (sentinel #999999) | **0.354** | 0.883 | 0.605 | 0.999 | 1.05 | 0.758 | 0.535 |
| `GET /items-qb` | 20 of 1000 items (page 1, + COUNT) | **0.283** | 0.713 | 0.556 | 0.805 | 0.972 | 0.555 | 0.346 |
| `GET /items-qb/1` | 1 item by id | **0.228** | 0.579 | 0.461 | 0.763 | 0.934 | 0.490 | 0.254 |
| `POST /items-qb` | 1 row upserted (sentinel #999997) | **0.309** | 0.726 | 0.519 | 0.851 | 1.11 | 0.643 | 0.315 |
| `GET /api/items` | 20 of 1000 items as JSON | **0.257** | 0.801 | 0.559 | 0.835 | 0.890 | 0.532 | 0.369 |
| `GET /api/items-fast` | 20 of 1000 items as JSON, rows not hydrated | **0.228** | 0.591 | 0.497 | 0.766 | 0.779 | 0.539 | 0.379 |
| `GET /api/items/1` | 1 item by id as JSON | **0.210** | 0.667 | 0.460 | 0.806 | 0.790 | 0.546 | 0.330 |
| `POST /api/items` | 1 row upserted (sentinel #999998) | **0.266** | 0.852 | 0.505 | 0.968 | 0.928 | 0.677 | 0.463 |
| `GET /features/aop` | 1 row upserted (sentinel #888800) | **0.238** | 0.626 | 0.350 | 0.876 | 0.953 | 0.413 | — |
| `GET /features/aop-direct` | 1 row upserted (sentinel #888800) | **0.233** | 0.609 | 0.335 | 0.859 | 0.934 | 0.419 | — |
| `GET /features/cache` | COUNT(*) of 1000 rows, cached 10s (miss = query) | **0.176** | 0.408 | 0.250 | 0.728 | 0.581 | 0.247 | 0.183 |
| `GET /features/log` | no DB — buffered log handlers | **0.176** | 0.375 | 0.255 | 0.720 | 0.595 | 0.241 | 0.186 |
| `GET /features/retry` | no DB — retry policy | **0.172** | 0.391 | 0.291 | 0.730 | 0.619 | 0.282 | 0.173 |
| `GET /features/pipeline` | no DB — middleware pipeline | **0.175** | 0.392 | 0.285 | 0.714 | 0.658 | 0.289 | 0.191 |
| `GET /features/db-events` | 1 row upserted (sentinel #888800) + COUNT(*), events collected | **0.266** | 0.680 | 0.549 | 0.972 | 1.00 | 0.629 | 0.270 |
| `GET /features/events` | no DB — in-process listeners | **0.243** | 0.577 | 0.340 | 0.857 | 0.971 | 0.517 | 1.93 |
| `GET /features/validation` | no DB — validator run | **0.183** | 1.12 | 0.447 | 0.784 | 0.886 | 0.461 | 0.220 |
| `GET /features/config` | no DB — config lookup | **0.170** | 0.362 | 0.250 | 0.696 | 0.639 | 0.278 | 0.182 |
| `GET /features/request-scoped` | no DB — scoped service resolve | **0.171** | 0.370 | 0.248 | 0.713 | 0.650 | 0.273 | 0.173 |
| `GET /features/rate-limit` | no DB — cache-backed limiter | **0.171** | 0.467 | 0.258 | 0.722 | 0.665 | 0.261 | 0.179 |

**Full comparison** — this page is a summary. The complete report, with every feature chart and the endpoint table for all six frameworks, is published at:

- The complete report (all seven frameworks, every deployment model) — <https://sailantis.github.io/azera-competition/benchmarks/>
- This model, measured end to end — <https://sailantis.github.io/azera-competition/benchmarks/view-real-volucer-attach.html>
- The per-request model (PHP-FPM) — <https://sailantis.github.io/azera-competition/benchmarks/view-summary-fpm.html>

---

> **Auto-generated.** This page and its charts are produced by the `azera-competition` repository:
> `php run.php --apps=azera,laravel,symfony,spiral,codeigniter,cakephp --warm --cold --seed --out=results/free-for-all-opcache-iso`
> then `php scripts/derive-fpm.php results/free-for-all-opcache-iso` and `php scripts/report.php --publish=framework`. Do not edit by hand — re-run the benchmark to update it.

Every chart is a plain SVG generated from the result JSON, so the numbers and the diagrams can never disagree.
