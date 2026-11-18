# Framework Competition — Volucer Summary

The headline numbers from the measured Volucer deployment. Volucer runs the app as a resident worker under a supervisor the deployment owns: the framework boots ONCE, before the supervisor forks, and every request is served from that already-built state. End-to-end HTTP over loopback, so the constant webserver overhead is included.

**Environment** — PHP 8.5.11 · Linux 6.8.0-139-generic · JIT: tracing (buffer 64M) · 1000 iterations per run over 10 runs, lower is better.

**Frameworks** — Azera v0.5.0 · Laravel 12.69.2 · Symfony 7.4.18 · Spiral 3.17.2 · CodeIgniter 4.7.4 · CakePHP 5.4.0.

_Measured 2026-11-18T16:24:17+00:00_

## Total response times

Total time to serve one pass over every benchmarked endpoint — each framework's sum of its endpoint medians, not a single response time — drawn relative to the baseline, so a row states how many times the baseline's own total it needed. Each endpoint's median is boot-inclusive occupancy for this view's deployment model, so the total is the worker time one pass over every endpoint costs. The chart orders the frameworks by that total and prints each one's multiplier beside its row.

![Total response times](images/benchmarks/summary-volucer/speedup.svg)

## Feature benchmarks

One race per framework feature, each run as a real request against a real database. Each feature states the request it measures under its own heading. Every figure — the winner of each race and the margin over the runner-up — is in that feature's own chart below, which anchors each endpoint at its fastest framework.

### Routing
 `GET /` — dispatches a request through the router and returns a rendered template — no database access. The `/echo` band runs the same path with a constant handler instead, so what the template render costs can be read off directly rather than inferred.

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

| Request | Workload | Azera | Laravel | Symfony | Spiral | CodeIgniter | CakePHP |
|---|---|---:|---:|---:|---:|---:|---:|
| `GET /` | no DB — routing + template only | **0.192** | 0.391 | 0.248 | 0.499 | 0.643 | 0.297 |
| `GET /echo` | no DB — routing + dispatch only | **0.172** | 0.385 | 0.225 | 0.511 | 0.510 | 0.243 |
| `GET /items` | 20 of 1000 items (page 1, + COUNT) | **0.297** | 0.893 | 0.800 | 0.821 | 1.05 | 0.768 |
| `GET /items-fast` | 20 of 1000 items (page 1, + COUNT), hydrated rows not built | **0.270** | 0.742 | 0.768 | 0.799 | 0.991 | 0.721 |
| `GET /items/1` | 1 item by id | **0.216** | 0.756 | 0.461 | 0.704 | 0.911 | 0.605 |
| `POST /items` | 1 row upserted (sentinel #999999) | **0.282** | 0.815 | 0.677 | 0.762 | 1.05 | 0.693 |
| `GET /items-qb` | 20 of 1000 items (page 1, + COUNT) | **0.255** | 0.750 | 0.521 | 0.716 | 0.968 | 0.535 |
| `GET /items-qb/1` | 1 item by id | **0.224** | 0.720 | 0.417 | 0.649 | 0.849 | 0.478 |
| `POST /items-qb` | 1 row upserted (sentinel #999997) | **0.278** | 0.771 | 0.579 | 0.711 | 1.05 | 0.558 |
| `GET /api/items` | 20 of 1000 items as JSON | **0.268** | 0.776 | 0.522 | 0.640 | 0.886 | 0.492 |
| `GET /api/items-fast` | 20 of 1000 items as JSON, rows not hydrated | **0.235** | 0.524 | 0.464 | 0.572 | 0.830 | 0.513 |
| `GET /api/items/1` | 1 item by id as JSON | **0.207** | 0.659 | 0.422 | 0.643 | 0.816 | 0.529 |
| `POST /api/items` | 1 row upserted (sentinel #999998) | **0.238** | 0.646 | 0.628 | 0.710 | 0.897 | 0.641 |
| `GET /features/aop` | no DB — interceptor pipeline | **0.333** | 0.563 | 0.488 | 0.822 | — | — |
| `GET /features/cache` | COUNT(*) of 1000 rows, cached 10s (miss = query) | **0.174** | 0.380 | 0.256 | 0.577 | 0.640 | 0.248 |
| `GET /features/log` | no DB — buffered log handlers | **0.184** | 0.356 | 0.250 | 0.571 | — | — |
| `GET /features/retry` | no DB — retry policy | **0.181** | 0.377 | 0.264 | 0.589 | — | — |
| `GET /features/pipeline` | no DB — middleware pipeline | **0.185** | 0.374 | 0.258 | 0.618 | — | — |
| `GET /features/db-events` | 1 event row INSERTed per request | **0.251** | 0.629 | 0.519 | 0.798 | 1.00 | 0.574 |
| `GET /features/events` | no DB — in-process listeners | **0.234** | 0.521 | 0.359 | 0.669 | 0.971 | 0.481 |
| `GET /features/validation` | no DB — validator run | **0.189** | 1.12 | 0.468 | 0.623 | 0.930 | 0.373 |
| `GET /features/config` | no DB — config lookup | **0.173** | 0.430 | 0.233 | 0.565 | 0.635 | 0.245 |
| `GET /features/request-scoped` | no DB — scoped service resolve | **0.178** | 0.425 | 0.234 | 0.585 | 0.632 | 0.262 |
| `GET /features/rate-limit` | no DB — cache-backed limiter | **0.179** | 0.457 | 0.241 | 0.595 | 0.642 | 0.256 |

**Full comparison** — this page is a summary. The complete report, with every feature chart and the endpoint table for all six frameworks, is published at:

- The complete report (all six frameworks, every deployment model) — <https://sailantis.github.io/azera-competition/benchmarks/>
- This model, measured end to end — <https://sailantis.github.io/azera-competition/benchmarks/view-real-volucer-attach.html>
- The per-request model (PHP-FPM) — <https://sailantis.github.io/azera-competition/benchmarks/view-summary-fpm.html>

---

> **Auto-generated.** This page and its charts are produced by the `azera-competition` repository:
> `php run.php --apps=azera,laravel,symfony,spiral,codeigniter,cakephp --warm --cold --seed --out=results/free-for-all-opcache-iso`
> then `php scripts/derive-fpm.php results/free-for-all-opcache-iso` and `php scripts/report.php --publish=framework`. Do not edit by hand — re-run the benchmark to update it.

Every chart is a plain SVG generated from the result JSON, so the numbers and the diagrams can never disagree.
