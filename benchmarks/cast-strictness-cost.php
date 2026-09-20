<?php

declare(strict_types=1);

/**
 * Micro-benchmark: strict IntCast/FloatCast::decode vs the old bare casts.
 *
 * The uncommitted casts made decode STRICT (reject garbage instead of
 * coercing to 0). This measures the price of strictness per value, for
 * the two input shapes real drivers produce:
 *   string digits (pgsql, mysql-emulated) and native ints/floats (sqlite,
 *   mysql-native).
 *
 * Run: php benchmarks/cast-strictness-cost.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Azera\Orm\Casting\FloatCast;
use Azera\Orm\Casting\IntCast;

const N      = 2_000_000;
const ROUNDS = 5;

/**
 * @return float ns per op (min of rounds - least contaminated)
 */
function bench(callable $fn, mixed $value): float
{
    // warmup
    for ($i = 0; $i < 100_000; $i++) {
        $fn($value);
    }

    $best = \INF;
    for ($r = 0; $r < ROUNDS; $r++) {
        $t0 = hrtime(true);
        for ($i = 0; $i < N; $i++) {
            $fn($value);
        }
        $ns   = (hrtime(true) - $t0) / N;
        $best = \min($best, $ns);
    }
    return $best;
}

$int   = new IntCast();
$float = new FloatCast();

$oldInt   = fn($v) => $v === null ? null : (int) $v;
$oldFloat = fn($v) => $v === null ? null : (float) $v;

printf("PHP %s | %d ops x %d rounds (min)\n\n", PHP_VERSION, N, ROUNDS);

echo "=== IntCast::decode ===\n";
printf("%-34s %8.2f ns/op\n", "old bare (int), string '123'", bench($oldInt, '123'));
printf("%-34s %8.2f ns/op\n", "new strict, string '123'", bench($int->decode(...), '123'));
printf("%-34s %8.2f ns/op\n", "old bare (int), int 123", bench($oldInt, 123));
printf("%-34s %8.2f ns/op\n", "new strict, int 123", bench($int->decode(...), 123));
printf("%-34s %8.2f ns/op\n", "old bare (int), string ' 42 '", bench($oldInt, ' 42 '));
printf("%-34s %8.2f ns/op\n", "new strict, string ' 42 '", bench($int->decode(...), ' 42 '));
echo "\n";

echo "=== FloatCast::decode ===\n";
printf("%-34s %8.2f ns/op\n", "old bare (float), string '12.5'", bench($oldFloat, '12.5'));
printf("%-34s %8.2f ns/op\n", "new strict, string '12.5'", bench($float->decode(...), '12.5'));
printf("%-34s %8.2f ns/op\n", "old bare (float), float 12.5", bench($oldFloat, 12.5));
printf("%-34s %8.2f ns/op\n", "new strict, float 12.5", bench($float->decode(...), 12.5));
echo "\n";

echo "=== snapshot read-back (put() vs old raw passthrough) ===\n";
// The old hydrate stored $row[$col] raw in the snapshot; the new put()
// reads the property back (isset + read). Isolated cost of that read:
class P
{
    public $x;
}
$p        = new P();
$readBack = function () use ($p) {
    $p->x = '5';
    return isset($p->x) ? $p->x : null;
};
$rawPass = function () {
    $p = null;
    $p = '5';
    return $p;
};
printf("%-34s %8.2f ns/op\n", "old: store raw cell", bench($rawPass, '5'));
printf("%-34s %8.2f ns/op\n", "new: write + read back", bench($readBack, '5'));