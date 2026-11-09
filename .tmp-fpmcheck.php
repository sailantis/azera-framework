<?php
// Simulates an fpm worker: one process, many requests, a fresh Router per request.
// The table is written during request 1 by this same process, then served.
require $argv[1];

use Azera\Core\Router;

$requests = (int) ($argv[2] ?? 50);
$dir      = '/tmp/fpm-check';
$cache    = $dir . '/routes.php';
$src      = $dir . '/src.php';
@mkdir($dir, 0700, true);
if (!is_file($src)) {
    file_put_contents($src, "<?php\n");
    touch($src, time() - 600);
}

function unit(): Closure
{
    return function (Router $r): void {
        for ($i = 0; $i < 120; $i++) {
            $r->get('/r' . $i . '/{id:int}', 'C' . $i . '::a');
        }
    };
}

function request(string $cache, string $src): float
{
    $t = hrtime(true);
    $r = (new Router())->setCachePath($cache);
    $r->setRevalidateFreq(60);
    $r->cached($src, unit());
    $r->match('/r50/7', 'GET');

    return (hrtime(true) - $t) / 1000;
}

@unlink($cache);
clearstatcache(true, $cache);

$first = request($cache, $src);
$times = [];
for ($i = 1; $i < $requests; $i++) {
    $times[] = request($cache, $src);
}

sort($times);
$n = count($times);
printf(
    "request1=%7.1fus  cached=%s  req2..%d median=%6.1fus p10=%6.1fus p90=%6.1fus\n",
    $first,
    var_export(@opcache_is_script_cached($cache), true),
    $requests,
    $times[intdiv($n, 2)],
    $times[intdiv($n, 10)],
    $times[intdiv($n * 9, 10)],
);

// A second, genuinely separate process must also find it warm.
printf("mtime is %d, now is %d (protection %ss)\n",
    filemtime($cache), time(), ini_get('opcache.file_update_protection'));
