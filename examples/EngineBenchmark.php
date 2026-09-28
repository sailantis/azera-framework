<?php
namespace Azera\EngineBenchmark;

/**
 * Engine Benchmark: NativeEngine vs ClarityEngine
 *
 * Measures the raw rendering throughput of both engines on an identical
 * template.  The Clarity cache is warmed up before the timed loop so
 * compilation cost is excluded from the results.
 *
 * Usage:
 *   php examples/EngineBenchmark.php
 *   php examples/EngineBenchmark.php 5000   # custom iteration count
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Azera\Core\Engines\ClarityEngine;
use Azera\Core\Engines\NativeEngine;

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

$iterations = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 2000;

$vars = [
    'title'   => 'Benchmark Page',
    'user'    => ['name' => 'Alice', 'role' => 'admin'],
    'items'   => ['Apple', 'Banana', 'Cherry', 'Date', 'Elderberry'],
    'show'    => true,
    'count'   => 42,
    'message' => '<b>Hello & welcome!</b>',
];

// ---------------------------------------------------------------------------
// Temporary directories
// ---------------------------------------------------------------------------

$baseDir    = sys_get_temp_dir() . '/merlin_bench_' . bin2hex(random_bytes(4));
$nativeDir  = $baseDir . '/native';
$clarityDir = $baseDir . '/clarity';
$cacheDir   = $baseDir . '/cache';

foreach ([$nativeDir, $clarityDir, $cacheDir] as $dir) {
    mkdir($dir, 0755, true);
}

// ---------------------------------------------------------------------------
// Template content (semantically identical for both engines)
// ---------------------------------------------------------------------------

// Native PHP template
$nativeTemplate = <<<'PHP'
<html>
<head><title><?= htmlspecialchars($title) ?></title></head>
<body>
<h1><?= htmlspecialchars($title) ?></h1>
<p>Welcome, <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)</p>
<?php if ($show): ?>
<p>Count: <?= (int) $count ?></p>
<p><?= htmlspecialchars($message) ?></p>
<ul>
<?php foreach ($items as $item): ?>
  <li><?= htmlspecialchars($item) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</body>
</html>
PHP;

// Clarity template (same output, Clarity syntax)
$clarityTemplate = <<<'CLARITY'
<html>
<head><title>{{ title }}</title></head>
<body>
<h1>{{ title }}</h1>
<p>Welcome, {{ user:name }} ({{ user:role }})</p>
{% if show %}
<p>Count: {{ count }}</p>
<p>{{ message }}</p>
<ul>
{% for item in items %}
  <li>{{ item }}</li>
{% endfor %}
</ul>
{% endif %}
</body>
</html>
CLARITY;

file_put_contents("{$nativeDir}/bench.php", $nativeTemplate);
file_put_contents("{$clarityDir}/bench.clarity.html", $clarityTemplate);

// ---------------------------------------------------------------------------
// Engine setup
// ---------------------------------------------------------------------------

$native = new NativeEngine();
$native->setViewPath($nativeDir);

$clarity = new ClarityEngine();
$clarity->setViewPath($clarityDir)->setCachePath($cacheDir);

// ---------------------------------------------------------------------------
// Warm up Clarity cache (compile once – not measured)
// ---------------------------------------------------------------------------

$clarity->renderPartial('bench', $vars);

// Optional: also warm up the native engine's opcode cache
$native->renderPartial('bench', $vars);

// ---------------------------------------------------------------------------
// Benchmark helper
// ---------------------------------------------------------------------------

function bench(callable $fn, int $n): array
{
    // Small forced GC before each run to reduce interference
    gc_collect_cycles();

    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    $elapsed = hrtime(true) - $start;

    $totalMs = $elapsed / 1_000_000;
    $avgUs   = $elapsed / $n / 1_000;

    return ['total_ms' => $totalMs, 'avg_us' => $avgUs, 'iterations' => $n];
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

echo PHP_EOL;
echo "Azera Engine Benchmark" . PHP_EOL;
echo str_repeat('=', 50) . PHP_EOL;
echo "Iterations : {$iterations}" . PHP_EOL;
echo "PHP        : " . PHP_VERSION . PHP_EOL;
echo str_repeat('-', 50) . PHP_EOL;

$nativeResult  = bench(fn() => $native->renderPartial('bench', $vars), $iterations);
$clarityResult = bench(fn() => $clarity->renderPartial('bench', $vars), $iterations);

$ratio = $clarityResult['avg_us'] / max($nativeResult['avg_us'], 0.001);

printf("%-18s %10s %12s%s", 'Engine', 'Total (ms)', 'Avg (µs)', PHP_EOL);
echo str_repeat('-', 50) . PHP_EOL;
printf(
    "%-18s %10.3f %12.3f%s",
    'NativeEngine',
    $nativeResult['total_ms'],
    $nativeResult['avg_us'],
    PHP_EOL
);
printf(
    "%-18s %10.3f %12.3f%s",
    'ClarityEngine',
    $clarityResult['total_ms'],
    $clarityResult['avg_us'],
    PHP_EOL
);
echo str_repeat('-', 50) . PHP_EOL;
printf("Overhead   : %.2fx  (Clarity vs Native)%s", $ratio, PHP_EOL);
echo PHP_EOL;

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? removeDir($path) : unlink($path);
    }
    rmdir($dir);
}

removeDir($baseDir);