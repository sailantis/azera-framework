<?php

declare(strict_types=1);

/**
 * AOP overhead benchmark.
 *
 * Rows are paired: for each interceptor set there is an explicit-Pipeline row
 * and a generated-proxy row exercising the same advice, so the comparison is
 * like-for-like. The 3-interceptor rows use a WARM cache: both paths
 * short-circuit inside CacheInterceptor, so neither runs the Log interceptor
 * or the inner method. They measure dispatch + cache-hit cost, not full work.
 *
 * Run with xdebug off: php -d xdebug.mode=off benchmarks/aop-overhead.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Azera\Aop\Advised;
use Azera\Aop\Cache;
use Azera\Aop\CacheInterceptor;
use Azera\Aop\Log;
use Azera\Aop\LogInterceptor;
use Azera\Aop\Pipeline;
use Azera\Aop\ProxyFactory;
use Azera\Aop\Retry;
use Azera\Aop\RetryInterceptor;
use Psr\Log\AbstractLogger;
use Azera\Cache\ArrayCache;

#[Advised]
class BenchService
{
    public int $sink = 0;

    #[Retry(times: 1, backoff: 0)]
    public function one(int $n): int
    {
        return $this->sink = $n + 1;
    }

    #[Retry(times: 1, backoff: 0)]
    #[Log(level: 'debug')]
    public function two(int $n): int
    {
        return $this->sink = $n + 1;
    }

    #[Retry(times: 1, backoff: 0)]
    #[Log(level: 'debug')]
    #[Cache(ttl: 60)]
    public function three(int $n): int
    {
        return $this->sink = $n + 1;
    }
}

class NullLogger extends AbstractLogger
{
    public function log($level, $message, array $context = []): void
    {
    }
}

/** @param callable(): mixed $fn */
function bench(callable $fn, int $rounds = 5, int $iterations = 100_000): float
{
    for ($i = 0; $i < 5_000; $i++) {
        $fn();
    }

    $samples = [];
    for ($r = 0; $r < $rounds; $r++) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $fn();
        }
        $samples[] = (hrtime(true) - $start) / $iterations;
    }

    sort($samples);

    return $samples[intdiv(count($samples), 2)];
}

function rule(string $label = ''): void
{
    printf("\n%s%s\n", $label === '' ? '' : $label . ' ', str_repeat('-', max(0, 62 - strlen($label))));
}

echo "AOP overhead benchmark (PHP ", PHP_VERSION, ")\n";

if (extension_loaded('xdebug') && ini_get('xdebug.mode') !== 'off' && ini_get('xdebug.mode') !== '') {
    echo "\n!! xdebug is active. Run with -d xdebug.mode=off.\n";
}

$logger = new NullLogger();
$cache  = new ArrayCache();
$retry  = new RetryInterceptor(defaultTimes: 1, defaultBackoff: 0);
$log    = new LogInterceptor($logger);
$cacheI = new CacheInterceptor($cache);

$raw = new BenchService();

rule('Raw');
$rawOne = bench(fn() => $raw->one(7));
printf("%-52s %10.1f ns\n", 'raw method call', $rawOne);

rule('Explicit Pipeline (default identity: file:line via ReflectionFunction)');
$p0 = new Pipeline([]);
$p1 = new Pipeline([$retry]);
$p2 = new Pipeline([$retry, $log]);
$p3 = new Pipeline([$retry, $log, $cacheI]);

$explicitZero   = bench(fn() => $p0->call(fn(int $n) => $raw->one($n), [7]));
$explicitOne    = bench(fn() => $p1->call(fn(int $n) => $raw->one($n), [7]));
$explicitTwo    = bench(fn() => $p2->call(fn(int $n) => $raw->two($n), [7]));
$explicitThree  = bench(fn() => $p3->call(fn(int $n) => $raw->three($n), [7]));

printf("%-52s %10.1f ns\n", 'explicit, 0 interceptors', $explicitZero);
printf("%-52s %10.1f ns\n", 'explicit, 1 (Retry)', $explicitOne);
printf("%-52s %10.1f ns\n", 'explicit, 2 (Retry+Log)', $explicitTwo);
printf("%-52s %10.1f ns\n", 'explicit, 3 (Retry+Log+Cache, warm)', $explicitThree);

// The rows above allocate the call-site closure on every iteration. These rows hoist
// it, so they measure pipeline overhead alone — the same shape a caller gets when it
// wraps a long-lived callable (an invokable object, a first-class callable, a service
// method already held in a variable).
$one = static fn(int $n): int => $raw->one($n);
$two = static fn(int $n): int => $raw->two($n);

rule('Explicit Pipeline (closure hoisted, 0 interceptors)');
$explicitZeroHoisted = bench(fn() => $p0->call($one, [7]));
$explicitOneHoisted  = bench(fn() => $p1->call($one, [7]));
printf("%-52s %10.1f ns\n", 'explicit, 0 interceptors', $explicitZeroHoisted);
printf("%-52s %10.1f ns\n", 'explicit, 1 (Retry), identity via ReflectionFunction', $explicitOneHoisted);

rule('Explicit Pipeline (caller supplies identity — no reflection)');
$explicitOneI = bench(fn() => $p1->call(fn(int $n) => $raw->one($n), [7], 'bench.one'));
$explicitTwoI = bench(fn() => $p2->call(fn(int $n) => $raw->two($n), [7], 'bench.two'));
printf("%-52s %10.1f ns\n", 'explicit, 1 (Retry), identity passed', $explicitOneI);
printf("%-52s %10.1f ns\n", 'explicit, 2 (Retry+Log), identity passed', $explicitTwoI);

rule('Transparent proxy (generated, advice baked)');
$factory = new ProxyFactory();
$factory->setCacheDir(null); // eval mode; same generated code the file cache writes
$factory->register(Retry::class, $retry);
$factory->register(Log::class, $log);
$factory->register(Cache::class, $cacheI);
$proxyClass = $factory->buildProxyClass(new ReflectionClass(BenchService::class));
$proxy = new $proxyClass();
$proxy->one(1);
$proxy->two(1);
$proxy->three(1); // warm the cache entry for the 3-interceptor row

$proxyOne   = bench(fn() => $proxy->one(7));
$proxyTwo   = bench(fn() => $proxy->two(7));
$proxyThree = bench(fn() => $proxy->three(7));

printf("%-52s %10.1f ns\n", 'proxy, 1 (Retry)', $proxyOne);
printf("%-52s %10.1f ns\n", 'proxy, 2 (Retry+Log)', $proxyTwo);
printf("%-52s %10.1f ns\n", 'proxy, 3 (Retry+Log+Cache, warm)', $proxyThree);

rule('Delta');
printf("%-52s %+10.1f ns\n", 'explicit-0 minus raw (closure allocated per call)', $explicitZero - $rawOne);
printf("%-52s %+10.1f ns\n", 'explicit-0 minus raw (closure hoisted)', $explicitZeroHoisted - $rawOne);
printf("%-52s %+10.1f ns\n", 'proxy-1 minus explicit-1', $proxyOne - $explicitOne);
printf("%-52s %+10.1f ns\n", 'proxy-2 minus explicit-2', $proxyTwo - $explicitTwo);
printf("%-52s %+10.1f ns\n", 'proxy-3 minus explicit-3 (warm)', $proxyThree - $explicitThree);

echo "\n";