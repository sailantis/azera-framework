<?php

declare(strict_types=1);

/**
 * Router matching benchmark.
 *
 * Reports the two numbers that matter for a routing change:
 *
 *   1. Cost per candidate, which is what the compiled fast path attacks. If this
 *      does not fall as a bucket grows, the chunking is not paying for itself.
 *   2. The named real-world cases, so a regression in the 404 or 405 path is as
 *      visible as one in a hit.
 *
 * Run with xdebug off, or the numbers are meaningless:
 *
 *   php -d xdebug.mode=off benchmarks/router-match.php
 *
 * Measured before the compiled index (flat ~4.7 us per candidate, so a bucket of 60
 * routes cost ~277 us to match one at the end) and after it, where the per-route
 * figure falls as the bucket grows: the work is one `preg_match` per 128 candidates
 * rather than one token walk per candidate.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Azera\Core\Router;

/**
 * @param callable(): mixed $fn
 */
function bench(callable $fn, int $iterations = 200_000): float
{
    // Warm up: first calls build the compiled index, and a cold measurement would
    // report that one-time cost as if it were per-request.
    for ($i = 0; $i < 5_000; $i++) {
        $fn();
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }

    return ((hrtime(true) - $start) / $iterations) / 1_000;
}

function rule(string $label = ''): void
{
    printf("\n%s%s\n", $label === '' ? '' : $label . ' ', str_repeat('-', max(0, 62 - strlen($label))));
}

echo "Router match benchmark (PHP ", PHP_VERSION, ")\n";

if (extension_loaded('xdebug')) {
    $mode = (string) ini_get('xdebug.mode');
    if ($mode !== '' && $mode !== 'off') {
        echo "\n!! xdebug is active (mode={$mode}). Run with -d xdebug.mode=off.\n";
    }
}

rule('Cost per candidate by bucket size');
printf("%-8s %10s %10s %10s %14s\n", 'routes', 'hit first', 'hit last', 'miss', 'us per route');

foreach ([1, 5, 20, 60, 200] as $size) {
    $router = new Router();
    for ($i = 0; $i < $size; $i++) {
        $router->get("/users/{id:int}/sub{$i}", "U{$i}::show");
    }

    $first = '/users/42/sub0';
    $last  = '/users/42/sub' . ($size - 1);
    $miss  = '/users/42/nothere';

    $hitFirst = bench(fn() => $router->match($first, 'GET'));
    $hitLast  = bench(fn() => $router->match($last, 'GET'));
    $missed   = bench(fn() => $router->match($miss, 'GET'));

    printf(
        "%-8d %10.3f %10.3f %10.3f %14.4f\n",
        $size,
        $hitFirst,
        $hitLast,
        $missed,
        $hitLast / $size,
    );
}

rule('Mixed table (40 static, 60 dynamic)');

$router = new Router();
for ($i = 0; $i < 40; $i++) {
    $router->get("/static/p{$i}", "C{$i}::index");
}
for ($i = 0; $i < 20; $i++) {
    $router->get("/users/{id:int}/sub{$i}", "U{$i}::show");
}
for ($i = 0; $i < 20; $i++) {
    $router->get("/{lang}/page{$i}", "P{$i}::show");
}
for ($i = 0; $i < 10; $i++) {
    $router->get("/files/{path:*}/x{$i}", "F{$i}::get");
}
for ($i = 0; $i < 10; $i++) {
    $router->get("/opt/{id?:int}/y{$i}", "O{$i}::get");
}

$cases = [
    'static hit'       => fn() => $router->match('/static/p7', 'GET'),
    'static, last'     => fn() => $router->match('/static/p39', 'GET'),
    'typed hit'        => fn() => $router->match('/users/42/sub3', 'GET'),
    'typed, last'      => fn() => $router->match('/users/42/sub19', 'GET'),
    'dynamic first'    => fn() => $router->match('/en/page4', 'GET'),
    'wildcard'         => fn() => $router->match('/files/a/b/c/x2', 'GET'),
    'optional'         => fn() => $router->match('/opt/9/y1', 'GET'),
    'optional absent'  => fn() => $router->match('/opt/y1', 'GET'),
    '404'              => fn() => $router->match('/no/such/thing', 'GET'),
];

foreach ($cases as $label => $fn) {
    printf("%-24s %8.3f us\n", $label, bench($fn));
}

$allowCases = [
    'getAllowedMethods (405)' => fn() => $router->getAllowedMethods('/users/42/nothere'),
    'getAllowedMethods (404)' => fn() => $router->getAllowedMethods('/no/such/thing'),
    'allowedMethods (405)'    => fn() => $router->allowedMethods('/users/42/nothere'),
    'allowedMethods (404)'    => fn() => $router->allowedMethods('/no/such/thing'),
];

// This table contains /{lang}/pageN, so the existence check cannot answer "no" for
// an unknown path and defers to the full scan. The group below shows the guarded
// case on a table without a leading-parameter route.
foreach ($allowCases as $label => $fn) {
    printf("%-24s %8.3f us\n", $label, bench($fn, 20_000));
}

rule('Existence guard (no leading-parameter route)');

$guarded = new Router();
for ($i = 0; $i < 40; $i++) {
    $guarded->get("/static/p{$i}", "C{$i}::index");
}
for ($i = 0; $i < 40; $i++) {
    $guarded->get("/users/{id:int}/sub{$i}", "U{$i}::show");
}
for ($i = 0; $i < 20; $i++) {
    $guarded->get("/files/{path:*}/x{$i}", "F{$i}::get");
}
for ($i = 0; $i < 20; $i++) {
    $guarded->post("/api/v1/item{$i}/{id:int}", "A{$i}::post");
}

// Warm the compiled index so the measurement is the steady state.
$guarded->match('/static/p7', 'GET');
$guarded->allowedMethods('/nope');

foreach ([
    'allowedMethods (404)'    => fn() => $guarded->allowedMethods('/nope/deep/path'),
    'getAllowedMethods (404)' => fn() => $guarded->getAllowedMethods('/nope/deep/path'),
    'allowedMethods (405)'    => fn() => $guarded->allowedMethods('/api/v1/item3/9'),
    'getAllowedMethods (405)' => fn() => $guarded->getAllowedMethods('/api/v1/item3/9'),
    'match (404), reference'  => fn() => $guarded->match('/nope/deep/path', 'GET'),
] as $label => $fn) {
    printf("%-24s %8.3f us\n", $label, bench($fn, 20_000));
}

rule('Cold compile cost (one-time, per process or per cache miss)');

$coldTimes = [];
for ($sample = 0; $sample < 25; $sample++) {
    $cold = new Router();
    for ($i = 0; $i < 300; $i++) {
        $cold->get("/r{$i}/{id:int}/leaf{$i}", "C{$i}::show");
    }

    // Only the match is timed: the add() calls above are what a bootstrap does
    // anyway, and they are unchanged by this work.
    $start = hrtime(true);
    $cold->match('/r0/42/leaf0', 'GET');
    $coldTimes[] = (hrtime(true) - $start) / 1_000;
}

sort($coldTimes);
printf(
    "%-24s %8.1f us median (300 routes, p90 %.1f us)\n",
    'first match (compile)',
    $coldTimes[intdiv(\count($coldTimes), 2)],
    $coldTimes[(int) floor((\count($coldTimes) - 1) * 0.9)],
);

echo "\n";
