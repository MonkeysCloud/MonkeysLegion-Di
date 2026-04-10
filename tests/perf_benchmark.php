<?php
declare(strict_types=1);

/**
 * MonkeysLegion DI v2 — Performance Benchmark
 *
 * Run: php tests/perf_benchmark.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MonkeysLegion\DI\Container;

// ── Fixtures ────────────────────────────────────────────────────

interface BenchLoggerInterface {}

class BenchFileLogger implements BenchLoggerInterface {}

class BenchServiceA
{
    public function __construct(
        public readonly BenchLoggerInterface $logger,
    ) {}
}

class BenchServiceB
{
    public function __construct(
        public readonly BenchServiceA $a,
        public readonly BenchLoggerInterface $logger,
    ) {}
}

class BenchServiceC
{
    public function __construct(
        public readonly BenchServiceB $b,
        public readonly BenchServiceA $a,
        public readonly BenchLoggerInterface $logger,
    ) {}
}

class BenchSimple {}

// ── Benchmark Runner ────────────────────────────────────────────

function bench(string $label, int $iterations, callable $fn): float
{
    // Warm up
    for ($i = 0; $i < 10; $i++) {
        $fn();
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    $elapsed = (hrtime(true) - $start) / 1_000_000; // ms

    $perOp = $elapsed / $iterations;
    $opsPerSec = $iterations / ($elapsed / 1000);

    printf(
        "  %-40s %8.3f ms total | %8.4f ms/op | %10s ops/sec\n",
        $label,
        $elapsed,
        $perOp,
        number_format($opsPerSec, 0),
    );

    return $elapsed;
}

// ── Run Benchmarks ──────────────────────────────────────────────

echo "╔══════════════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  MonkeysLegion DI v2 — Performance Benchmark                                       ║\n";
echo "║  PHP " . PHP_VERSION . str_repeat(' ', 60 - strlen(PHP_VERSION)) . "║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════════════╝\n\n";

$n = 10_000;

// 1. Simple resolve (no deps, cached singleton)
echo "── Singleton Resolution ────────────────────────────────────\n";
bench("get() cached singleton", $n, function () {
    $c = new Container();
    $c->get(BenchSimple::class);
    $c->get(BenchSimple::class); // second call = cached
});

// 2. Auto-wire with deps
echo "\n── Auto-wiring ─────────────────────────────────────────────\n";
Container::clearReflectionCache();

bench("Auto-wire (0 deps) COLD", 1000, function () {
    Container::clearReflectionCache();
    $c = new Container();
    $c->get(BenchSimple::class);
});

bench("Auto-wire (0 deps) WARM", $n, function () {
    $c = new Container();
    $c->get(BenchSimple::class);
});

bench("Auto-wire (1 dep) WARM", $n, function () {
    $c = new Container();
    $c->bind(BenchLoggerInterface::class, BenchFileLogger::class);
    $c->get(BenchServiceA::class);
});

bench("Auto-wire (3 deps, nested) WARM", $n, function () {
    $c = new Container();
    $c->bind(BenchLoggerInterface::class, BenchFileLogger::class);
    $c->get(BenchServiceC::class);
});

// 3. make() (always new)
echo "\n── make() (Transient) ──────────────────────────────────────\n";
bench("make() (0 deps)", $n, function () {
    $c = new Container();
    $c->make(BenchSimple::class);
});

bench("make() (1 dep)", $n, function () {
    $c = new Container();
    $c->bind(BenchLoggerInterface::class, BenchFileLogger::class);
    $c->make(BenchServiceA::class);
});

// 4. call() (method injection)
echo "\n── call() (Method Injection) ───────────────────────────────\n";
$callContainer = new Container();
$callContainer->bind(BenchLoggerInterface::class, BenchFileLogger::class);

bench("call() closure", $n, function () use ($callContainer) {
    $callContainer->call(fn(BenchLoggerInterface $log) => $log);
});

// 5. Contextual binding
echo "\n── Contextual Binding ──────────────────────────────────────\n";
bench("Contextual resolve", $n, function () {
    $c = new Container();
    $c->bind(BenchLoggerInterface::class, BenchFileLogger::class);
    $c->contextual(BenchServiceA::class, BenchLoggerInterface::class, BenchFileLogger::class);
    $c->get(BenchServiceA::class);
});

// 6. Alias resolution
echo "\n── Alias Resolution ───────────────────────────────────────\n";
bench("Alias → class resolve", $n, function () {
    $c = new Container();
    $c->alias('bench.simple', BenchSimple::class);
    $c->get('bench.simple');
});

// 7. Reflection cache impact
echo "\n── Reflection Cache Impact ─────────────────────────────────\n";
Container::clearReflectionCache();
$coldTime = bench("10K resolves (cold cache)", $n, function () {
    Container::clearReflectionCache();
    $c = new Container();
    $c->bind(BenchLoggerInterface::class, BenchFileLogger::class);
    $c->get(BenchServiceA::class);
});

$warmTime = bench("10K resolves (warm cache)", $n, function () {
    $c = new Container();
    $c->bind(BenchLoggerInterface::class, BenchFileLogger::class);
    $c->get(BenchServiceA::class);
});

$speedup = ($coldTime - $warmTime) / $coldTime * 100;
printf("\n  ⚡ Reflection cache speedup: %.1f%%\n", $speedup);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "  ✅ All benchmarks complete\n";
echo "══════════════════════════════════════════════════════════════\n";
