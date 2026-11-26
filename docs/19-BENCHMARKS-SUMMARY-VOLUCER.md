# Framework Competition — Volucer Summary

The headline numbers from the measured Volucer deployment. Volucer runs the app as a resident worker under a supervisor the deployment owns: the framework boots ONCE, before the supervisor forks, and every request is served from that already-built state. End-to-end HTTP over loopback, so the constant webserver overhead is included.

**Environment** — PHP 8.5.11 · Linux 6.8.0-139-generic · OPcache: yes · JIT: tracing (buffer 64M) · 1000 iterations per run over 10 runs, lower is better.

**Frameworks** — Azera v0.6.0-10-g3926ba6 · Laravel 12.69.2 · Symfony 7.4.18 · Spiral 3.17.2 · CodeIgniter 4.7.4 · CakePHP 5.4.0 · Phalcon 6.0.0RC4.

_Measured 2026-11-26T01:57:45+00:00_

## Total response times

Total time to serve one pass over the 23 endpoints EVERY entrant races (3 more are raced by only some, and are left out here so no framework is charged for work it does not do) — each framework's sum of its endpoint medians, not a single response time — drawn relative to the baseline, so a row states how many times the baseline's own total it needed. Each endpoint's median is boot-inclusive occupancy for this view's deployment model, so the total is the worker time one pass over those endpoints costs. The chart orders the frameworks by that total and prints each one's multiplier beside its row.

![Total response times](images/benchmarks/summary-volucer/speedup.svg)

## Feature benchmarks

One race per framework feature, each run as a real request against a real database. Each feature states the request it measures under its own heading. Every figure — the winner of each race and the margin over the runner-up — is in that feature's own chart below, which anchors each endpoint at its fastest framework.

### Routing
 `GET /api/routing/1` — matches a typed route and dispatches it to a constant handler — no template and no database access — so the band measures route matching and dispatch and nothing else. The endpoint sits in a deep bucket of the route table, which is where a compiled index actually saves work. `/echo` is drawn beside it: the same constant handler behind a STATIC route, i.e. the floor that route matching is measured against — and, like any floor, it is mostly the SERVER rather than the framework. Measured 2026-11-24 with `temp/echo-ab.php` on the bench VM, the entire framework layer on `/echo` costs single-digit microseconds, inside rows that print two to three orders of magnitude more. A difference of a few microseconds there is below the run-to-run spread, so read `/echo` as the common floor and NOT as a comparison between entrants; the charts that separate frameworks are the ones with real work in them.

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

Median in milliseconds, lower is better. **Bold** = fastest for that endpoint; a trailing `≈` = within the run-to-run noise band of the fastest, so read it as a tie rather than a defeat (`Tables::TIE_BAND` states the band and how it was calibrated). The median is the statistic the charts beside this table draw, so a cell and its bar are the same number; the dataset also carries a trimmed mean, which sits a few percent higher and ranks identically in all but two dead-heat cells, and it stays available for the outlier-robust cross-check rather than as a second published figure. Every number is END-TO-END per-request occupancy for the view's deployment model: the framework boot of that model is part of the cell, not parked in a separate chart. These are REAL deployments measured over HTTP: every row carries the constant server cost, which is why the values cluster — the floor note below states what stands under them. The workload column states what each request reads or writes. Every framework runs the same seeded database and the same page size, so the payload is identical no matter which framework served it; the workload column is the part of the suite that varies.

These rows are end-to-end for a resident worker with a pool that never recycles it (one long-lived worker): the worker booted once before the first request and serves the whole run, so a cell is the measured request itself and carries no boot. The one-time recycle cost is measured in the full report linked at the foot of this page — read that when sizing a pool that is recycled or restarted, or when requests queue behind one worker.

**Server floor** — a resident worker that boots NOTHING (`floor-volucer-attach`) measures this server's own overhead: a bare request round trip with no framework, no routing and no template, stated as that floor's `GET /echo` row in the dataset. Every row below stands on it, so only differences larger than it are framework differences — and a row BELOW it is a measurement that describes a different server than its floor, which is what FloorCoverageTest and bench-lib.php::assertRowsNotBelowFloor() now refuse.

**Run-to-run spread** — a resident arm's rows are mostly the server, so a cell moves by a few percent between two runs of unchanged code, and by more than a tenth on roughly one cell in five. Read a gap smaller than that band as a tie, which is what the trailing `≈` in the table marks, and prefer the rows where real work is done over the routing-only ones when the question is which framework is faster.

| Request | Workload | Azera | Laravel | Symfony | Spiral | CodeIgniter | CakePHP | Phalcon |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| `GET /api/routing/1` | no DB — typed route + constant body (routing only) | **0.159** | 0.336 | 0.244 | 0.660 | 0.537 | 0.242 | 0.182 |
| `GET /` | no DB — routing + template only | **0.175** | 0.427 | 0.340 | 0.680 | 0.680 | 0.315 | 0.208 |
| `GET /echo` | no DB — static route, constant body (throughput floor) | **0.155** | 0.351 | 0.252 | 0.631 | 0.507 | 0.248 | 0.179 |
| `GET /items` | 20 of 1000 items (page 1, + COUNT) | **0.284** | 0.918 | 0.844 | 0.952 | 1.02 | 0.768 | 0.662 |
| `GET /items-fast` | 20 of 1000 items (page 1, + COUNT), hydrated rows not built | **0.274** | 0.765 | 0.787 | 0.906 | 0.974 | 0.699 | 0.602 |
| `GET /items/1` | 1 item by id | **0.208** | 0.669 | 0.496 | 0.815 | 0.916 | 0.630 | 0.368 |
| `POST /items` | 1 row upserted (sentinel #999999) | **0.270** | 0.836 | 0.588 | 0.987 | 1.03 | 0.802 | 0.547 |
| `GET /items-qb` | 20 of 1000 items (page 1, + COUNT) | **0.267** | 0.675 | 0.506 | 0.759 | 0.972 | 0.587 | 0.329 |
| `GET /items-qb/1` | 1 item by id | **0.236** | 0.579 | 0.366 | 0.752 | 0.899 | 0.501 | 0.240≈ |
| `POST /items-qb` | 1 row upserted (sentinel #999997) | 0.292≈ | 0.702 | 0.429 | 0.822 | 1.08 | 0.606 | **0.288** |
| `GET /api/items` | 20 of 1000 items as JSON | **0.255** | 0.784 | 0.480 | 0.813 | 0.839 | 0.501 | 0.361 |
| `GET /api/items-fast` | 20 of 1000 items as JSON, rows not hydrated | **0.221** | 0.521 | 0.459 | 0.756 | 0.846 | 0.505 | 0.423 |
| `GET /api/items/1` | 1 item by id as JSON | **0.206** | 0.603 | 0.440 | 0.746 | 0.835 | 0.505 | 0.378 |
| `POST /api/items` | 1 row upserted (sentinel #999998) | **0.240** | 0.731 | 0.469 | 0.904 | 0.881 | 0.668 | 0.482 |
| `GET /features/aop-tx` | 1 row upserted (sentinel #888800) | **0.221** | — | — | — | — | — | — |
| `GET /features/aop-tx-direct` | 1 row upserted (sentinel #888800) | **0.218** | 0.487 | 0.316 | 0.756 | 0.918 | 0.486 | 0.245 |
| `GET /features/aop-tx-explicit` | 1 row upserted (sentinel #888800) | **0.226** | — | — | 0.799 | — | — | — |
| `GET /features/aop-cache` | COUNT(*) of 1000 rows, cached 10s by #[Cache] (miss = query) | **0.177** | — | — | — | — | — | — |
| `GET /features/aop-cache-direct` | COUNT(*) of 1000 rows, cached 10s by hand (miss = query) | **0.174** | 0.332 | 0.231 | 0.650 | 0.592 | 0.292 | 0.189 |
| `GET /features/log` | no DB — one in-memory log sink per entrant | **0.166** | 0.446 | 0.233 | 0.706 | 0.646 | 0.287 | 0.188 |
| `GET /features/db-events` | 1 row upserted (sentinel #888800) + COUNT(*), events collected | **0.226** | 0.601 | 0.543 | 0.937 | 1.01 | 0.621 | 0.263 |
| `GET /features/events` | no DB — in-process listeners | **0.220** | 0.501 | 0.390 | 0.806 | 0.945 | 0.512 | 1.86 |
| `GET /features/validation` | no DB — validator run | **0.185** | 1.06 | 0.488 | 0.735 | 0.864 | 0.460 | 0.221 |
| `GET /features/config` | no DB — config lookup | **0.177** | 0.375 | 0.274 | 0.684 | 0.587 | 0.271 | 0.184 |
| `GET /features/rate-limit` | no DB — cache-backed limiter | **0.177** | 0.355 | 0.283 | 0.693 | 0.605 | 0.280 | 0.183 |
| `GET /features/lifecycle-hooks` | no DB — a request/response hook stamps a header and sets 203, via each framework's own mechanism | **0.177** | 0.318 | 0.268 | 0.661 | 0.615 | 0.282 | 0.181≈ |

**Full comparison** — this page is a summary. The complete report, with every feature chart and the endpoint table for all six frameworks, is published at:

- The complete report (all seven frameworks, every deployment model) — <https://sailantis.github.io/azera-competition/benchmarks/>
- This model, measured end to end — <https://sailantis.github.io/azera-competition/benchmarks/view-real-volucer-attach.html>
- The per-request model (PHP-FPM) — <https://sailantis.github.io/azera-competition/benchmarks/view-summary-fpm.html>

---

> **Auto-generated.** This page and its charts are produced by the `azera-competition` repository:
> `php run.php --apps=azera,laravel,symfony,spiral,codeigniter,cakephp --warm --cold --seed --out=results/free-for-all-opcache-iso`
> then `php scripts/derive-fpm.php results/free-for-all-opcache-iso` and `php scripts/report.php --publish=framework`. Do not edit by hand — re-run the benchmark to update it.

Every chart is a plain SVG generated from the result JSON, so the numbers and the diagrams can never disagree.
