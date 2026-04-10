<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Tests\Unit;

use MonkeysLegion\DI\Attributes\Factory;
use MonkeysLegion\DI\Container;
use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\DI\Contracts\ContainerInterface;
use MonkeysLegion\DI\Contracts\ServiceProviderInterface;
use PHPUnit\Framework\TestCase;

// ══════════════════════════════════════════════════════════════════
// Fixtures
// ══════════════════════════════════════════════════════════════════

interface ImpLoggerInterface
{
    public function log(string $msg): string;
}

class ImpFileLogger implements ImpLoggerInterface
{
    public function log(string $msg): string
    {
        return "file:{$msg}";
    }
}

class ImpDecoratingLogger implements ImpLoggerInterface
{
    public function __construct(
        private readonly ImpLoggerInterface $inner,
        public readonly string $prefix = 'decorated',
    ) {}

    public function log(string $msg): string
    {
        return "{$this->prefix}({$this->inner->log($msg)})";
    }

    public function getInner(): ImpLoggerInterface
    {
        return $this->inner;
    }
}

#[Factory(method: 'create')]
class ImpFactoryService
{
    // Private constructor — only reachable via #[Factory]
    private function __construct(public readonly string $value = 'factory-built') {}

    public static function create(): self
    {
        return new self();
    }
}

class ImpSimpleService
{
    public string $id;

    public function __construct()
    {
        $this->id = uniqid('', true);
    }
}

// ── Service provider fixture ────────────────────────────────────

class ImpServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->bind(ImpLoggerInterface::class, ImpFileLogger::class);
        $builder->set('imp.greeting', fn() => 'hello from provider');
    }
}

// ══════════════════════════════════════════════════════════════════
// Tests
// ══════════════════════════════════════════════════════════════════

class ContainerImprovementsTest extends TestCase
{
    protected function setUp(): void
    {
        Container::clearReflectionCache();
        Container::resetInstance();
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
        Container::clearReflectionCache();
    }

    // ── Bug fix: has() with #[Factory] classes ──────────────────

    public function testHasReturnsTrueForFactoryClass(): void
    {
        $c = new Container();
        // ImpFactoryService has a private constructor, so isInstantiable()
        // returns false — but the container CAN resolve it via #[Factory].
        self::assertTrue($c->has(ImpFactoryService::class));
    }

    public function testGetWorksForFactoryClass(): void
    {
        $c = new Container();
        $svc = $c->get(ImpFactoryService::class);
        self::assertInstanceOf(ImpFactoryService::class, $svc);
        self::assertSame('factory-built', $svc->value);
    }

    public function testHasAndGetAreConsistentForFactoryClass(): void
    {
        $c = new Container();
        // PSR-11 requires: if has() is true, get() must not throw NotFoundExceptionInterface.
        self::assertTrue($c->has(ImpFactoryService::class));
        self::assertInstanceOf(ImpFactoryService::class, $c->get(ImpFactoryService::class));
    }

    // ── Performance: has() uses reflection cache ────────────────

    public function testHasUsesReflectionCacheOnSubsequentCalls(): void
    {
        $c = new Container();

        // First call populates cache
        $c->has(ImpSimpleService::class);

        // Warm path — should still return correct result
        self::assertTrue($c->has(ImpSimpleService::class));
        self::assertFalse($c->has('NonExistentClass\\Foo'));
    }

    // ── extend(): cached singleton ──────────────────────────────

    public function testExtendWrapsAlreadyCachedSingleton(): void
    {
        $c = new Container();
        $c->bind(ImpLoggerInterface::class, ImpFileLogger::class);

        // Resolve and cache
        $original = $c->get(ImpLoggerInterface::class);
        self::assertInstanceOf(ImpFileLogger::class, $original);

        // Extend the already-cached service
        $c->extend(ImpLoggerInterface::class, function (mixed $svc, Container $container): ImpDecoratingLogger {
            return new ImpDecoratingLogger($svc);
        });

        $extended = $c->get(ImpLoggerInterface::class);
        self::assertInstanceOf(ImpDecoratingLogger::class, $extended);
        self::assertSame('decorated(file:test)', $extended->log('test'));
    }

    // ── extend(): wraps factory definition ─────────────────────

    public function testExtendWrapsExistingFactory(): void
    {
        $c = new Container();
        $c->set(ImpLoggerInterface::class, fn() => new ImpFileLogger());

        $c->extend(ImpLoggerInterface::class, function (mixed $svc, Container $container): ImpDecoratingLogger {
            return new ImpDecoratingLogger($svc, 'wrapped');
        });

        $svc = $c->get(ImpLoggerInterface::class);
        self::assertInstanceOf(ImpDecoratingLogger::class, $svc);
        self::assertSame('wrapped(file:hello)', $svc->log('hello'));
    }

    // ── extend(): wraps auto-wired class ────────────────────────

    public function testExtendWrapsAutoWiredClass(): void
    {
        $c = new Container();

        // No explicit definition — extend wraps auto-wiring
        $c->extend(ImpFileLogger::class, function (mixed $svc, Container $container): ImpDecoratingLogger {
            return new ImpDecoratingLogger($svc, 'autowired');
        });

        $svc = $c->get(ImpFileLogger::class);
        self::assertInstanceOf(ImpDecoratingLogger::class, $svc);
        self::assertSame('autowired(file:x)', $svc->log('x'));
    }

    // ── extend(): factory is called only once (singleton) ──────

    public function testExtendResultIsMemoised(): void
    {
        $callCount = 0;
        $c = new Container();
        $c->set('svc', fn() => new ImpSimpleService());
        $c->extend('svc', function (mixed $svc) use (&$callCount): mixed {
            $callCount++;
            return $svc;
        });

        $c->get('svc');
        $c->get('svc');

        // Factory + extender invoked once; second get() returns cached instance
        self::assertSame(1, $callCount);
    }

    // ── extend(): multiple extensions applied in order ──────────

    public function testExtendMultipleExtensionsAppliedInOrder(): void
    {
        $c = new Container();
        $c->set(ImpLoggerInterface::class, fn() => new ImpFileLogger());

        $c->extend(ImpLoggerInterface::class, fn($s) => new ImpDecoratingLogger($s, 'first'));
        $c->extend(ImpLoggerInterface::class, fn($s) => new ImpDecoratingLogger($s, 'second'));

        $svc = $c->get(ImpLoggerInterface::class);
        // second wraps first, which wraps original
        self::assertSame('second(first(file:x))', $svc->log('x'));
    }

    // ── getBindings() introspection ─────────────────────────────

    public function testGetBindingsReturnsRegisteredBindings(): void
    {
        $c = new Container();
        $c->bind(ImpLoggerInterface::class, ImpFileLogger::class);

        $bindings = $c->getBindings();
        self::assertArrayHasKey(ImpLoggerInterface::class, $bindings);
        self::assertSame(ImpFileLogger::class, $bindings[ImpLoggerInterface::class]);
    }

    public function testGetBindingsEmptyByDefault(): void
    {
        $c = new Container();
        self::assertSame([], $c->getBindings());
    }

    // ── getAliases() introspection ──────────────────────────────

    public function testGetAliasesReturnsRegisteredAliases(): void
    {
        $c = new Container();
        $c->alias('imp.logger', ImpFileLogger::class);

        $aliases = $c->getAliases();
        self::assertArrayHasKey('imp.logger', $aliases);
        self::assertSame(ImpFileLogger::class, $aliases['imp.logger']);
    }

    public function testGetAliasesEmptyByDefault(): void
    {
        $c = new Container();
        self::assertSame([], $c->getAliases());
    }

    // ── getTransients() introspection ───────────────────────────

    public function testGetTransientsReturnsMarkedIds(): void
    {
        $c = new Container();
        $c->transient(ImpSimpleService::class);

        $transients = $c->getTransients();
        self::assertContains(ImpSimpleService::class, $transients);
    }

    public function testGetTransientsEmptyByDefault(): void
    {
        $c = new Container();
        self::assertSame([], $c->getTransients());
    }

    // ── ContainerBuilder::addServiceProvider() ──────────────────

    public function testAddServiceProviderRegistersBindings(): void
    {
        $builder = new ContainerBuilder();
        $builder->addServiceProvider(new ImpServiceProvider());

        $container = $builder->build();

        self::assertInstanceOf(ImpFileLogger::class, $container->get(ImpLoggerInterface::class));
        self::assertSame('hello from provider', $container->get('imp.greeting'));
    }

    public function testAddServiceProviderFluentReturnsSelf(): void
    {
        $builder = new ContainerBuilder();
        self::assertSame($builder, $builder->addServiceProvider(new ImpServiceProvider()));
    }

    // ── ContainerBuilder::extend() ──────────────────────────────

    public function testBuilderExtendIsAppliedAfterBuild(): void
    {
        $builder = new ContainerBuilder();
        $builder->set(ImpLoggerInterface::class, fn() => new ImpFileLogger());
        $builder->extend(ImpLoggerInterface::class, fn($s) => new ImpDecoratingLogger($s, 'built'));

        $container = $builder->build();
        $svc = $container->get(ImpLoggerInterface::class);

        self::assertInstanceOf(ImpDecoratingLogger::class, $svc);
        self::assertSame('built(file:x)', $svc->log('x'));
    }

    public function testBuilderExtendFluentReturnsSelf(): void
    {
        $builder = new ContainerBuilder();
        self::assertSame($builder, $builder->extend('foo', fn($s) => $s));
    }

    public function testBuilderExtendMultipleExtensionsAppliedInOrder(): void
    {
        $builder = new ContainerBuilder();
        $builder->set(ImpLoggerInterface::class, fn() => new ImpFileLogger());
        $builder->extend(ImpLoggerInterface::class, fn($s) => new ImpDecoratingLogger($s, 'A'));
        $builder->extend(ImpLoggerInterface::class, fn($s) => new ImpDecoratingLogger($s, 'B'));

        $container = $builder->build();
        $svc = $container->get(ImpLoggerInterface::class);

        self::assertSame('B(A(file:x))', $svc->log('x'));
    }
}
