<?php
declare(strict_types=1);

namespace MonkeysLegion\DI\Tests\Unit;

use MonkeysLegion\DI\Attributes\Alias;
use MonkeysLegion\DI\Attributes\Decorator;
use MonkeysLegion\DI\Attributes\Factory;
use MonkeysLegion\DI\Attributes\Lazy;
use MonkeysLegion\DI\Container;
use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\DI\Contracts\ContainerInterface;
use MonkeysLegion\DI\Exceptions\CircularDependencyException;
use MonkeysLegion\DI\Exceptions\ServiceResolveException;
use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerInterface as PsrContainerInterface;

// ══════════════════════════════════════════════════════════════════
// Test Fixtures
// ══════════════════════════════════════════════════════════════════

interface V2LoggerInterface
{
    public function log(string $message): string;
}

class V2FileLogger implements V2LoggerInterface
{
    public function log(string $message): string
    {
        return "file: {$message}";
    }
}

class V2ConsoleLogger implements V2LoggerInterface
{
    public function log(string $message): string
    {
        return "console: {$message}";
    }
}

// ── Contextual binding fixtures ─────────────────────────────────

class V2ServiceA
{
    public function __construct(
        public readonly V2LoggerInterface $logger,
    ) {}
}

class V2ServiceB
{
    public function __construct(
        public readonly V2LoggerInterface $logger,
    ) {}
}

// ── Factory attribute fixture ───────────────────────────────────

#[Factory(method: 'create')]
class V2FactoryService
{
    private function __construct(
        public readonly string $value,
    ) {}

    public static function create(): self
    {
        return new self('created-via-factory');
    }
}

#[Factory(method: 'build')]
class V2FactoryWithDeps
{
    private function __construct(
        public readonly V2LoggerInterface $logger,
    ) {}

    public static function build(V2LoggerInterface $logger): self
    {
        return new self($logger);
    }
}

// ── Alias attribute fixture ─────────────────────────────────────

#[Alias('my.logger')]
#[Alias('app.logger')]
class V2AliasedLogger implements V2LoggerInterface
{
    public function log(string $message): string
    {
        return "aliased: {$message}";
    }
}

// ── Lazy attribute fixtures ─────────────────────────────────────

#[Lazy]
class V2HeavyService
{
    public static int $instanceCount = 0;

    // Instance property required for PHP 8.4 lazy proxy to intercept
    public string $state = '';

    public function __construct()
    {
        self::$instanceCount++;
        $this->state = 'ready';
    }

    public function compute(): string
    {
        return "computed:{$this->state}";
    }
}

class V2LazyConsumer
{
    public function __construct(
        #[Lazy] public readonly V2FileLogger $logger,
    ) {}
}

// ── Decorator fixture ───────────────────────────────────────────

#[Decorator(decorates: V2LoggerInterface::class)]
class V2CachingLogger implements V2LoggerInterface
{
    public function __construct(
        private readonly V2LoggerInterface $inner,
    ) {}

    public function log(string $message): string
    {
        return "cached({$this->inner->log($message)})";
    }

    public function getInner(): V2LoggerInterface
    {
        return $this->inner;
    }
}

// ── Method injection fixtures ───────────────────────────────────

class V2MethodService
{
    public function process(V2LoggerInterface $logger, string $name = 'default'): string
    {
        return $logger->log($name);
    }

    public static function staticProcess(V2LoggerInterface $logger): string
    {
        return $logger->log('static');
    }
}

// ── make() fixtures ─────────────────────────────────────────────

class V2Transient
{
    public static int $count = 0;

    public function __construct(
        public readonly string $label = 'default',
    ) {
        self::$count++;
    }
}

// ══════════════════════════════════════════════════════════════════
// Tests
// ══════════════════════════════════════════════════════════════════

class ContainerV2Test extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        Container::clearReflectionCache();
        $this->container = new Container();
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
        Container::clearReflectionCache();
        V2HeavyService::$instanceCount = 0;
        V2Transient::$count = 0;
    }

    // ── Contracts ───────────────────────────────────────────────

    public function testImplementsContainerInterface(): void
    {
        self::assertInstanceOf(ContainerInterface::class, $this->container);
        self::assertInstanceOf(PsrContainerInterface::class, $this->container);
    }

    public function testGetReturnsContainerForMlInterface(): void
    {
        $result = $this->container->get(ContainerInterface::class);
        self::assertSame($this->container, $result);
    }

    // ── Contextual Binding ──────────────────────────────────────

    public function testContextualBindingBasic(): void
    {
        $this->container->bind(V2LoggerInterface::class, V2FileLogger::class);

        // ServiceA gets FileLogger (default), ServiceB gets ConsoleLogger
        $this->container->contextual(
            V2ServiceB::class,
            V2LoggerInterface::class,
            V2ConsoleLogger::class,
        );

        $a = $this->container->get(V2ServiceA::class);
        $b = $this->container->get(V2ServiceB::class);

        self::assertInstanceOf(V2FileLogger::class, $a->logger);
        self::assertInstanceOf(V2ConsoleLogger::class, $b->logger);
    }

    public function testContextualBindingWithFactory(): void
    {
        $this->container->bind(V2LoggerInterface::class, V2FileLogger::class);

        $this->container->contextual(
            V2ServiceB::class,
            V2LoggerInterface::class,
            fn() => new V2ConsoleLogger(),
        );

        $b = $this->container->get(V2ServiceB::class);
        self::assertInstanceOf(V2ConsoleLogger::class, $b->logger);
    }

    // ── make() ──────────────────────────────────────────────────

    public function testMakeCreatesNewInstance(): void
    {
        $a = $this->container->make(V2Transient::class);
        $b = $this->container->make(V2Transient::class);

        self::assertNotSame($a, $b);
        self::assertSame(2, V2Transient::$count);
    }

    public function testMakeWithParamOverrides(): void
    {
        $t = $this->container->make(V2Transient::class, ['label' => 'custom']);
        self::assertSame('custom', $t->label);
    }

    public function testMakeIgnoresSingletonCache(): void
    {
        // First get() caches as singleton
        $cached = $this->container->get(V2Transient::class);
        // make() ignores cache
        $fresh = $this->container->make(V2Transient::class);

        self::assertNotSame($cached, $fresh);
    }

    // ── call() ──────────────────────────────────────────────────

    public function testCallClosure(): void
    {
        $this->container->bind(V2LoggerInterface::class, V2FileLogger::class);

        $result = $this->container->call(
            fn(V2LoggerInterface $logger) => $logger->log('hello'),
        );

        self::assertSame('file: hello', $result);
    }

    public function testCallInstanceMethod(): void
    {
        $this->container->bind(V2LoggerInterface::class, V2FileLogger::class);
        $service = new V2MethodService();

        $result = $this->container->call([$service, 'process']);
        self::assertSame('file: default', $result);
    }

    public function testCallInstanceMethodWithParams(): void
    {
        $this->container->bind(V2LoggerInterface::class, V2FileLogger::class);
        $service = new V2MethodService();

        $result = $this->container->call([$service, 'process'], ['name' => 'custom']);
        self::assertSame('file: custom', $result);
    }

    public function testCallStaticMethod(): void
    {
        $this->container->bind(V2LoggerInterface::class, V2FileLogger::class);

        $result = $this->container->call([V2MethodService::class, 'staticProcess']);
        self::assertSame('file: static', $result);
    }

    public function testCallResolvesClassStringTarget(): void
    {
        $this->container->bind(V2LoggerInterface::class, V2FileLogger::class);

        $result = $this->container->call([V2MethodService::class, 'process']);
        self::assertSame('file: default', $result);
    }

    // ── alias() ─────────────────────────────────────────────────

    public function testAliasProgrammatic(): void
    {
        $this->container->set('original', fn() => new V2FileLogger());
        $this->container->alias('shortcut', 'original');

        $a = $this->container->get('original');
        $b = $this->container->get('shortcut');

        self::assertSame($a, $b);
    }

    public function testAliasHas(): void
    {
        $this->container->set('original', fn() => new V2FileLogger());
        $this->container->alias('shortcut', 'original');

        self::assertTrue($this->container->has('shortcut'));
    }

    public function testAliasAttribute(): void
    {
        $logger = $this->container->get(V2AliasedLogger::class);
        $byAlias1 = $this->container->get('my.logger');
        $byAlias2 = $this->container->get('app.logger');

        self::assertSame($logger, $byAlias1);
        self::assertSame($logger, $byAlias2);
    }

    // ── #[Factory] ──────────────────────────────────────────────

    public function testFactoryAttributeBasic(): void
    {
        $service = $this->container->get(V2FactoryService::class);
        self::assertSame('created-via-factory', $service->value);
    }

    public function testFactoryAttributeWithDeps(): void
    {
        $this->container->bind(V2LoggerInterface::class, V2FileLogger::class);
        $service = $this->container->get(V2FactoryWithDeps::class);

        self::assertInstanceOf(V2FileLogger::class, $service->logger);
    }

    // ── #[Lazy] ─────────────────────────────────────────────────

    public function testLazyClassAttributeDefersInstantiation(): void
    {
        V2HeavyService::$instanceCount = 0;

        // Getting the service should not instantiate it yet (lazy proxy)
        $proxy = $this->container->get(V2HeavyService::class);

        // Before first method call — constructor not run yet
        self::assertSame(0, V2HeavyService::$instanceCount);

        // First method call triggers actual instantiation
        $result = $proxy->compute();
        self::assertSame('computed:ready', $result);
        self::assertSame(1, V2HeavyService::$instanceCount);
    }

    public function testLazyParameterAttribute(): void
    {
        $consumer = $this->container->get(V2LazyConsumer::class);

        // The logger should be a lazy proxy — FileLogger constructor not called yet
        self::assertInstanceOf(V2FileLogger::class, $consumer->logger);
    }

    // ── #[Decorator] ────────────────────────────────────────────

    public function testDecoratorWrapsService(): void
    {
        // First register the base logger
        $this->container->bind(V2LoggerInterface::class, V2FileLogger::class);
        // Force the base to resolve
        $this->container->get(V2LoggerInterface::class);

        // Now resolve the decorator (it auto-wraps)
        $decorator = $this->container->get(V2CachingLogger::class);

        self::assertInstanceOf(V2CachingLogger::class, $decorator);
        self::assertSame('cached(file: test)', $decorator->log('test'));
    }

    // ── CircularDependencyException ─────────────────────────────

    public function testCircularDependencyExceptionHasChain(): void
    {
        $ex = new CircularDependencyException(['A', 'B', 'C', 'A']);

        self::assertSame(['A', 'B', 'C', 'A'], $ex->getChain());
        self::assertStringContainsString('A → B → C → A', $ex->getMessage());
    }

    public function testCircularDetectionUsesNewException(): void
    {
        $this->container->bind('A', 'B');
        $this->container->bind('B', 'A');

        $this->expectException(CircularDependencyException::class);
        $this->container->get('A');
    }

    // ── Reflection Cache ────────────────────────────────────────

    public function testReflectionCacheImprovesThroughput(): void
    {
        Container::clearReflectionCache();
        $this->container->bind(V2LoggerInterface::class, V2FileLogger::class);

        // Cold — reflection cache empty on each iteration
        $start = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            Container::clearReflectionCache();
            $c = new Container();
            $c->bind(V2LoggerInterface::class, V2FileLogger::class);
            $c->get(V2ServiceA::class);
        }
        $cold = (hrtime(true) - $start) / 1_000_000;

        // Warm — cache populated from previous iterations
        $start = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $c = new Container();
            $c->bind(V2LoggerInterface::class, V2FileLogger::class);
            $c->get(V2ServiceA::class);
        }
        $warm = (hrtime(true) - $start) / 1_000_000;

        // Warm should be faster — allow 10% tolerance for CI jitter
        self::assertLessThanOrEqual(
            $cold * 1.1,
            $warm,
            "Warm resolves ({$warm}ms) should not exceed cold ({$cold}ms) + 10% tolerance",
        );
    }

    public function testClearReflectionCache(): void
    {
        $this->container->get(V2FileLogger::class);
        Container::clearReflectionCache();

        // Should still work after clearing
        $c2 = new Container();
        $logger = $c2->get(V2FileLogger::class);
        self::assertInstanceOf(V2FileLogger::class, $logger);
    }

    // ── ContainerBuilder v2 ─────────────────────────────────────

    public function testBuilderContextual(): void
    {
        $builder = new ContainerBuilder();
        $builder->bind(V2LoggerInterface::class, V2FileLogger::class);
        $builder->contextual(V2ServiceB::class, V2LoggerInterface::class, V2ConsoleLogger::class);

        $container = $builder->build();

        $a = $container->get(V2ServiceA::class);
        $b = $container->get(V2ServiceB::class);

        self::assertInstanceOf(V2FileLogger::class, $a->logger);
        self::assertInstanceOf(V2ConsoleLogger::class, $b->logger);
    }

    public function testBuilderAlias(): void
    {
        $builder = new ContainerBuilder();
        $builder->set('original', fn() => new V2FileLogger());
        $builder->alias('shortcut', 'original');

        $container = $builder->build();

        self::assertSame(
            $container->get('original'),
            $container->get('shortcut'),
        );
    }

    public function testBuilderFluentReturnsTypes(): void
    {
        $builder = new ContainerBuilder();

        self::assertSame($builder, $builder->contextual('A', 'B', 'C'));
        self::assertSame($builder, $builder->alias('a', 'b'));
    }

    // ── reset() preserves all self-references ───────────────────

    public function testResetPreservesAllSelfReferences(): void
    {
        $this->container->get(V2FileLogger::class);
        $this->container->reset();

        self::assertSame($this->container, $this->container->get(PsrContainerInterface::class));
        self::assertSame($this->container, $this->container->get(ContainerInterface::class));
        self::assertSame($this->container, $this->container->get(Container::class));
    }
}
