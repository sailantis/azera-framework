<?php

declare(strict_types=1);

/**
 * Benchmark: ORM Metadata L2 cache (APCu) vs no L2 cache.
 *
 * Compiles a batch of fixture models through Metadata::for() under these
 * regimes:
 *
 *   1. cold  — no L2 wired, L1 empty (worst case: pure reflection compile)
 *   2. l1    — no L2 wired, L1 warm (the RoadRunner / long-lived worker case)
 *   3. l2    — L2 wired, L1 dropped every pass (fresh FPM worker, warm L2) —
 *              run per backend: apcu, redis (ext-redis + reachable server)
 *              and file (all from sailantis/azera-cache when loadable)
 *
 * Backends come from sailantis/azera-cache, loaded straight from the sibling
 * checkout when the framework does not vendor it; the APCu arm falls back to
 * a minimal in-file PSR-16 wrapper so the script always runs standalone.
 *
 * Run: php benchmarks/metadata-l2-cache.php [--models=N] [--iterations=K] [--rounds=R]
 */

require __DIR__ . '/../vendor/autoload.php';

use Azera\Orm\Metadata;

/* ------------------------------------------------------------------ fixtures */

/**
 * Fixture models with realistic attribute surface. Only these files differ
 * from the production compile path — nothing here is special-cased.
 */
abstract class AbstractBenchModel extends \Azera\Orm\Model
{
}

final class BenchUser extends AbstractBenchModel
{
    public int $id;
    public string $email;
    public string $name;
    public bool $active;
    public ?\DateTimeImmutable $created_at;

    #[\Azera\Orm\Attribute\Column(pk: true)]
    public int $uid;
}

final class BenchArticle extends AbstractBenchModel
{
    public int $id;
    public string $title;
    public string $body;
    public int $author_id;
    public ?\DateTimeImmutable $published_at;
    public array $tags;

    #[\Azera\Orm\Attribute\BelongsTo(target: BenchUser::class)]
    public ?BenchUser $author;
}

final class BenchComment extends AbstractBenchModel
{
    public int $id;
    public int $article_id;
    public int $author_id;
    public string $body;
    public bool $approved;

    #[\Azera\Orm\Attribute\BelongsTo(target: BenchArticle::class)]
    public ?BenchArticle $article;

    #[\Azera\Orm\Attribute\HasMany(target: BenchComment::class)]
    public array $replies;
}

/* --------------------------------------------------------- synthetic models */

/**
 * Generate $n distinct model classes, each with its own property mix
 * (column renames, pk marks, relations) so every one exercises a real
 * compile — a class compiles once and would otherwise be a pure L1 hit.
 */
function generate_models(int $n): array
{
    $props = ['sku', 'title', 'amount', 'active', 'due_at', 'meta', 'owner_id', 'score', 'slug', 'rank'];
    $types = ['string', 'string', 'float', 'bool', '?\DateTimeImmutable', 'array', 'int', 'int', 'string', 'int'];

    $code    = 'namespace BenchGen; use Azera\Orm\Attribute\Column; ';
    $classes = [];
    for ($i = 0; $i < $n; ++$i) {
        $cls = "GenModel{$i}";
        $classes[] = 'BenchGen\\' . $cls;

        // 3 props per class, picked by offset so the mixes differ per class.
        $body = '';
        for ($p = 0; $p < 3; ++$p) {
            $idx  = ($i * 3 + $p) % \count($props);
            $col  = $props[$idx];
            $type = $types[$idx];
            // Give the first prop of every 5th class a rename + pk mark.
            if ($p === 0 && $i % 5 === 0) {
                $body .= '    #[Column(name: "gen_col_' . $i . '", pk: true)] public ' . $type . ' $' . $col . ";\n";
            } else {
                $body .= '    public ' . $type . ' $' . $col . ";\n";
            }
        }

        $code .= 'class ' . $cls . ' extends \\Azera\\Orm\\Model { ' . $body . ' } ';
    }

    eval($code);

    return $classes;
}

/* ------------------------------------------------------------- apcu backend */

/**
 * Minimal PSR-16 APCu wrapper used when sailantis/azera-cache is not
 * vendored into the framework. Mirrors ApcuCache's wire format closely
 * enough (serialize on write) for comparable numbers.
 */
final class InlineApcuCache implements \Psr\SimpleCache\CacheInterface
{
    public function __construct(private string $prefix = 'bench:') {}

    public function get(string $key, mixed $default = null): mixed
    {
        $success = false;
        $raw     = apcu_fetch($this->prefix . $key, $success);
        if (!$success) {
            return $default;
        }
        $value = @unserialize($raw);

        return ($value === false && $raw !== serialize(false)) ? $default : $value;
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        return (bool) apcu_store($this->prefix . $key, serialize($value), $ttl ?? 0);
    }

    public function delete(string $key): bool
    {
        return (bool) apcu_delete($this->prefix . $key);
    }

    public function clear(): bool
    {
        foreach (apcu_cache_info(false)['cache_list'] ?? [] as $entry) {
            $name = $entry['info'] ?? null;
            if (is_string($name) && str_starts_with($name, $this->prefix)) {
                apcu_delete($name);
            }
        }

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        foreach ($values as $k => $v) {
            $this->set($k, $v, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return apcu_exists($this->prefix . $key);
    }
}

/* ------------------------------------------------------------------- harness */

/**
 * Median-of-runs micro timing around a closure, in milliseconds.
 */
function bench(string $label, int $rounds, callable $fn): float
{
    $times = [];
    for ($r = 0; $r < $rounds; ++$r) {
        $t0 = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $t0) / 1e6;
    }
    sort($times);
    $median = $times[intdiv(\count($times), 2)];
    printf("%-28s median %8.3f ms  (min %8.3f, max %8.3f)\n", $label, $median, $times[0], $times[\count($times) - 1]);

    return $median;
}

/* ---------------------------------------------------------------------- main */

$models     = (int) (getopt('', ['models:', 'iterations:', 'rounds:'])['models'] ?? 200);
$iterations = (int) (getopt('', ['models:', 'iterations:', 'rounds:'])['iterations'] ?? 1000);
$rounds     = (int) (getopt('', ['models:', 'iterations:', 'rounds:'])['rounds'] ?? 3);

if (!function_exists('apcu_fetch')) {
    fwrite(STDERR, "ext-apcu is required for the L2 arm of this benchmark.\n");
    exit(1);
}

printf(
    "PHP %s | apcu %s | redis %s | models=%d | iterations=%d | rounds=%d\n\n",
    PHP_VERSION,
    phpversion('apcu') ?: 'n/a',
    phpversion('redis') ?: 'n/a',
    $models,
    $iterations,
    $rounds,
);

$classes = generate_models($models);

// Load the production backends (sailantis/azera-cache) from the sibling
// checkout when the framework does not vendor the package. Dependency-free:
// PSR-16 + ext-apcu / ext-redis / plain files.
$backendDir = __DIR__ . '/../../azera-cache/src/Backend/';
if (!class_exists(\Azera\Cache\Backend\ApcuCache::class) && is_file($backendDir . 'ApcuCache.php')) {
    require_once $backendDir . 'ValidatesKeys.php';
    require_once $backendDir . 'ApcuCache.php';
    require_once $backendDir . 'FileCache.php';
    require_once $backendDir . 'RedisCache.php';
}

/**
 * One L2 arm: wire $backend, prime it once, then measure Metadata::for()
 * with the L1 tier freshly dropped every pass — a fresh worker reading a
 * warm shared L2 (Metadata::clearL1() resets the process tier WITHOUT
 * touching L2; Metadata::clear() would also delete the L2 keys).
 */
function runL2Arm(string $label, \Psr\SimpleCache\CacheInterface $backend, array $classes, int $iterations, int $rounds): float
{
    Metadata::useCache($backend, ttl: 3600);
    Metadata::clear(); // reset both tiers, then prime L2 once
    foreach ($classes as $c) {
        Metadata::for($c); // prime L2 (and L1) outside the loop
    }

    return bench($label, $rounds, function () use ($classes, $iterations) {
        for ($i = 0; $i < $iterations; ++$i) {
            Metadata::clearL1(); // L1 gone; the shared L2 survives
            foreach ($classes as $c) {
                Metadata::for($c);
            }
        }
    });
}

// --- cold: L1 empty, no L2. Each iteration compiles EVERY model fresh. ------
Metadata::useCache(null);
Metadata::clear();
$medianCold = bench('cold (compile only)', $rounds, function () use ($classes, $iterations) {
    for ($i = 0; $i < $iterations; ++$i) {
        Metadata::clear();
        foreach ($classes as $c) {
            Metadata::for($c);
        }
    }
});

// --- l1: no L2, L1 warm. After the first pass everything is an array hit. ---
Metadata::clear();
foreach ($classes as $c) {
    Metadata::for($c); // warm L1
}
$medianL1 = bench('l1 warm (no L2)', $rounds, function () use ($classes, $iterations) {
    for ($i = 0; $i < $iterations; ++$i) {
        foreach ($classes as $c) {
            Metadata::for($c);
        }
    }
});

// --- l2 arms: L1 dropped every pass, L2 warm. Simulates a fresh FPM worker
// (or a pool of workers on one host) pulling from a warm shared L2: every
// lookup is one fetch + unserialize instead of a reflection compile.
$arms = [];

$arms['apcu'] = class_exists(\Azera\Cache\Backend\ApcuCache::class)
    ? new \Azera\Cache\Backend\ApcuCache('bench:meta:')
    : new InlineApcuCache('bench:meta:');

if (class_exists(\Redis::class)) {
    $client = new \Redis();
    try {
        $client->connect('127.0.0.1', 6379, 1.0);
        if (class_exists(\Azera\Cache\Backend\RedisCache::class)) {
            $arms['redis'] = new \Azera\Cache\Backend\RedisCache($client, 'bench:meta:');
        }
    } catch (\Throwable) {
        fwrite(STDERR, "[skip] no reachable redis server on 127.0.0.1:6379\n");
    }
}

if (class_exists(\Azera\Cache\Backend\FileCache::class)) {
    $arms['file'] = new \Azera\Cache\Backend\FileCache(sys_get_temp_dir() . '/azera-meta-bench');
}

$medians = [];
foreach ($arms as $name => $armBackend) {
    $medians[$name] = runL2Arm("l2 $name (fresh L1, warm L2)", $armBackend, $classes, $iterations, $rounds);
}
Metadata::useCache(null);

/* -------------------------------------------------------------------- report */

printf(
    "\nper-compile medians: cold=%.4fus l1=%.4fus\n",
    $medianCold / ($iterations * $models) * 1000,
    $medianL1 / ($iterations * $models) * 1000,
);

$coldPer = $medianCold / ($iterations * $models) * 1000;
foreach ($medians as $name => $median) {
    $per = $median / ($iterations * $models) * 1000;
    if ($per >= $coldPer) {
        printf("L2 %-5s %9.4fus/model — %.1fx SLOWER than recompile\n", $name, $per, $per / $coldPer);
    } else {
        printf("L2 %-5s %9.4fus/model — %.1fx faster than recompile\n", $name, $per, $coldPer / $per);
    }
}