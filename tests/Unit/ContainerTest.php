<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Tests\Unit;

use MonkeysLegion\DI\Attributes\Inject;
use MonkeysLegion\DI\Attributes\Singleton;
use MonkeysLegion\DI\Attributes\Tagged;
use MonkeysLegion\DI\Attributes\Transient;
use MonkeysLegion\DI\Container;
use MonkeysLegion\DI\Exceptions\ServiceNotFoundException;
use MonkeysLegion\DI\Exceptions\ServiceResolveException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/* =====================================================================
 *  Fixture classes — kept minimal and within the test file.
 * =================================================================== */

class StubNoConstructor
{
    public string $value = 'hello';
}

class StubWithDependency
{
    public function __construct(public StubNoConstructor $dep) {}
}

class StubWithScalarDefault
{
    public function __construct(
        public StubNoConstructor $dep,
        public int $count = 42,
    ) {}
}

class StubWithNullable
{
    public function __construct(public ?StubNoConstructor $dep = null) {}
}

class StubCircularA
{
    public function __construct(public StubCircularB $b) {}
}

class StubCircularB
{
    public function __construct(public StubCircularA $a) {}
}

interface StubInterface
{
    public function value(): string;
}

class StubConcrete implements StubInterface
{
    public function value(): string { return 'concrete'; }
}

class StubConcreteAlt implements StubInterface
{
    public function value(): string { return 'alt'; }
}

class StubWithInterface
{
    public function __construct(public StubInterface $dep) {}
}

class StubWithUnionType
{
    public function __construct(public StubInterface|StubNoConstructor $dep) {}
}

class StubWithInject
{
    public function __construct(
        #[Inject('custom.logger')]
        public StubNoConstructor $logger,
    ) {}
}

#[Transient]
class StubTransient
{
    public string $id;

    public function __construct()
    {
        $this->id = uniqid('', true);
    }
}

#[Singleton]
class StubExplicitSingleton
{
    public string $id;

    public function __construct()
    {
        $this->id = uniqid('', true);
    }
}

#[Tagged('handler')]
#[Tagged('loggable')]
class StubTaggedHandler
{
    public function __construct() {}
}

#[Tagged('handler')]
class StubTaggedHandler2
{
    public function __construct() {}
}

class StubWithContainerDep
{
    public function __construct(public ContainerInterface $container) {}
}

abstract class StubAbstract
{
    abstract public function doSomething(): void;
}

/* =====================================================================
 *  Tests
 * =================================================================== */

class ContainerTest extends TestCase
{
    /* -----------------------------------------------------------------
     *  PSR-11 basics (v1.0 backward compat)
     * --------------------------------------------------------------- */

    #[Test]
    public function has_returns_false_for_unknown_id(): void
    {
        $c = new Container();
        $this->assertFalse($c->has('nonexistent.service'));
    }

    #[Test]
    public function has_returns_true_for_instantiable_class(): void
    {
        $c = new Container();
        $this->assertTrue($c->has(StubNoConstructor::class));
    }

    #[Test]
    public function has_returns_true_for_explicit_definition(): void
    {
        $c = new Container([
            'my.service' => fn() => new StubNoConstructor(),
        ]);
        $this->assertTrue($c->has('my.service'));
    }

    #[Test]
    public function get_throws_not_found_for_unknown(): void
    {
        $c = new Container();
        $this->expectException(ServiceNotFoundException::class);
        $c->get('does.not.exist');
    }

    #[Test]
    public function get_returns_container_for_psr11_interface(): void
    {
        $c = new Container();
        $this->assertSame($c, $c->get(ContainerInterface::class));
    }

    /* -----------------------------------------------------------------
     *  Auto-wiring (v1.0 backward compat)
     * --------------------------------------------------------------- */

    #[Test]
    public function auto_wires_class_without_constructor(): void
    {
        $c = new Container();
        $obj = $c->get(StubNoConstructor::class);
        $this->assertInstanceOf(StubNoConstructor::class, $obj);
        $this->assertSame('hello', $obj->value);
    }

    #[Test]
    public function auto_wires_class_with_dependency(): void
    {
        $c = new Container();
        $obj = $c->get(StubWithDependency::class);
        $this->assertInstanceOf(StubWithDependency::class, $obj);
        $this->assertInstanceOf(StubNoConstructor::class, $obj->dep);
    }

    #[Test]
    public function auto_wires_scalar_default(): void
    {
        $c = new Container();
        $obj = $c->get(StubWithScalarDefault::class);
        $this->assertSame(42, $obj->count);
    }

    #[Test]
    public function auto_wires_nullable_parameter_to_null_when_unresolvable(): void
    {
        $c = new Container();
        // StubNoConstructor IS resolvable so it will be injected
        $obj = $c->get(StubWithNullable::class);
        $this->assertInstanceOf(StubNoConstructor::class, $obj->dep);
    }

    #[Test]
    public function caches_singleton_by_default(): void
    {
        $c = new Container();
        $a = $c->get(StubNoConstructor::class);
        $b = $c->get(StubNoConstructor::class);
        $this->assertSame($a, $b);
    }

    /* -----------------------------------------------------------------
     *  Circular dependency detection (v1.0 backward compat)
     * --------------------------------------------------------------- */

    #[Test]
    public function detects_circular_dependency(): void
    {
        $c = new Container();
        $this->expectException(ServiceResolveException::class);
        $this->expectExceptionMessageMatches('/[Cc]ircular/');
        $c->get(StubCircularA::class);
    }

    /* -----------------------------------------------------------------
     *  Union types (v1.0 backward compat)
     * --------------------------------------------------------------- */

    #[Test]
    public function resolves_union_type_with_first_available(): void
    {
        $c = new Container();
        // StubInterface is not directly resolvable, but StubNoConstructor is
        $obj = $c->get(StubWithUnionType::class);
        $this->assertInstanceOf(StubNoConstructor::class, $obj->dep);
    }

    /* -----------------------------------------------------------------
     *  Factory definitions (v1.0 backward compat)
     * --------------------------------------------------------------- */

    #[Test]
    public function resolves_factory_definition(): void
    {
        $c = new Container([
            StubInterface::class => fn() => new StubConcrete(),
        ]);

        $obj = $c->get(StubInterface::class);
        $this->assertInstanceOf(StubConcrete::class, $obj);
        $this->assertSame('concrete', $obj->value());
    }

    #[Test]
    public function factory_receives_container(): void
    {
        $c = new Container([
            StubInterface::class => fn(ContainerInterface $c) => new StubConcrete(),
        ]);

        $this->assertInstanceOf(StubConcrete::class, $c->get(StubInterface::class));
    }

    #[Test]
    public function factory_that_returns_non_object_still_works(): void
    {
        // v1.1 broadened — factories may return scalars (like GraphQL's bool factories)
        $c = new Container([
            'graphql.routes' => fn() => true,
        ]);
        $this->assertTrue($c->get('graphql.routes'));
    }

    #[Test]
    public function factory_memoises_result(): void
    {
        $callCount = 0;
        $c = new Container([
            'counter' => function () use (&$callCount) {
                $callCount++;
                return new StubNoConstructor();
            },
        ]);

        $c->get('counter');
        $c->get('counter');
        $this->assertSame(1, $callCount);
    }

    /* -----------------------------------------------------------------
     *  Abstract class rejection
     * --------------------------------------------------------------- */

    #[Test]
    public function throws_for_abstract_class(): void
    {
        $c = new Container();
        $this->expectException(ServiceResolveException::class);
        $c->get(StubAbstract::class);
    }

    /* -----------------------------------------------------------------
     *  set() — runtime registration (v1.1)
     * --------------------------------------------------------------- */

    #[Test]
    public function set_registers_factory_at_runtime(): void
    {
        $c = new Container();
        $c->set('late.service', fn() => new StubNoConstructor());

        $this->assertTrue($c->has('late.service'));
        $this->assertInstanceOf(StubNoConstructor::class, $c->get('late.service'));
    }

    #[Test]
    public function set_registers_instance_at_runtime(): void
    {
        $c = new Container();
        $instance = new StubNoConstructor();
        $c->set(StubNoConstructor::class, $instance);

        $this->assertSame($instance, $c->get(StubNoConstructor::class));
    }

    #[Test]
    public function set_overrides_existing_definition(): void
    {
        $c = new Container([
            StubInterface::class => fn() => new StubConcrete(),
        ]);

        $c->set(StubInterface::class, fn() => new StubConcreteAlt());
        $obj = $c->get(StubInterface::class);
        $this->assertSame('alt', $obj->value());
    }

    /* -----------------------------------------------------------------
     *  bind() — interface binding (v1.1)
     * --------------------------------------------------------------- */

    #[Test]
    public function bind_maps_interface_to_concrete(): void
    {
        $c = new Container();
        $c->bind(StubInterface::class, StubConcrete::class);

        $this->assertTrue($c->has(StubInterface::class));
        $obj = $c->get(StubInterface::class);
        $this->assertInstanceOf(StubConcrete::class, $obj);
    }

    #[Test]
    public function bind_used_during_autowiring(): void
    {
        $c = new Container();
        $c->bind(StubInterface::class, StubConcrete::class);

        $obj = $c->get(StubWithInterface::class);
        $this->assertInstanceOf(StubConcrete::class, $obj->dep);
    }

    /* -----------------------------------------------------------------
     *  reset() — testing support (v1.1)
     * --------------------------------------------------------------- */

    #[Test]
    public function reset_clears_cached_instances(): void
    {
        $c = new Container();
        $a = $c->get(StubNoConstructor::class);
        $c->reset();
        $b = $c->get(StubNoConstructor::class);

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function reset_preserves_self_reference(): void
    {
        $c = new Container();
        $c->reset();
        $this->assertSame($c, $c->get(ContainerInterface::class));
    }

    /* -----------------------------------------------------------------
     *  #[Inject] attribute (v1.1)
     * --------------------------------------------------------------- */

    #[Test]
    public function inject_attribute_overrides_auto_wiring(): void
    {
        $custom = new StubNoConstructor();
        $custom->value = 'injected';

        $c = new Container([
            'custom.logger' => $custom,
        ]);

        $obj = $c->get(StubWithInject::class);
        $this->assertSame('injected', $obj->logger->value);
    }

    /* -----------------------------------------------------------------
     *  #[Transient] attribute (v1.1)
     * --------------------------------------------------------------- */

    #[Test]
    public function transient_attribute_creates_new_instance_each_time(): void
    {
        $c = new Container();
        $a = $c->get(StubTransient::class);
        $b = $c->get(StubTransient::class);

        $this->assertNotSame($a, $b);
        $this->assertNotSame($a->id, $b->id);
    }

    #[Test]
    public function programmatic_transient_creates_new_instance(): void
    {
        $c = new Container();
        $c->transient(StubNoConstructor::class);

        $a = $c->get(StubNoConstructor::class);
        $b = $c->get(StubNoConstructor::class);
        $this->assertNotSame($a, $b);
    }

    /* -----------------------------------------------------------------
     *  #[Singleton] attribute (v1.1) — confirms default behavior
     * --------------------------------------------------------------- */

    #[Test]
    public function explicit_singleton_caches(): void
    {
        $c = new Container();
        $a = $c->get(StubExplicitSingleton::class);
        $b = $c->get(StubExplicitSingleton::class);

        $this->assertSame($a, $b);
        $this->assertSame($a->id, $b->id);
    }

    /* -----------------------------------------------------------------
     *  #[Tagged] attribute + getTagged() (v1.1)
     * --------------------------------------------------------------- */

    #[Test]
    public function tagged_attribute_registers_tags_on_auto_wire(): void
    {
        $c = new Container();

        // Trigger auto-wiring so tags get registered
        $c->get(StubTaggedHandler::class);
        $c->get(StubTaggedHandler2::class);

        $handlers = $c->getTagged('handler');
        $this->assertCount(2, $handlers);
    }

    #[Test]
    public function tagged_attribute_with_multiple_tags(): void
    {
        $c = new Container();
        $c->get(StubTaggedHandler::class);

        $loggable = $c->getTagged('loggable');
        $this->assertCount(1, $loggable);
        $this->assertInstanceOf(StubTaggedHandler::class, $loggable[0]);
    }

    #[Test]
    public function programmatic_tag_works(): void
    {
        $c = new Container();
        $c->tag(StubNoConstructor::class, 'my.tag');

        $tagged = $c->getTagged('my.tag');
        $this->assertCount(1, $tagged);
        $this->assertInstanceOf(StubNoConstructor::class, $tagged[0]);
    }

    #[Test]
    public function get_tagged_returns_empty_for_unknown_tag(): void
    {
        $c = new Container();
        $this->assertSame([], $c->getTagged('nonexistent'));
    }

    /* -----------------------------------------------------------------
     *  Container injection (v1.1)
     * --------------------------------------------------------------- */

    #[Test]
    public function injects_container_interface(): void
    {
        $c = new Container();
        $obj = $c->get(StubWithContainerDep::class);
        $this->assertSame($c, $obj->container);
    }

    /* -----------------------------------------------------------------
     *  getDefinitions() (v1.1)
     * --------------------------------------------------------------- */

    #[Test]
    public function get_definitions_returns_all(): void
    {
        $factory = fn() => new StubNoConstructor();
        $c = new Container(['foo' => $factory]);

        $defs = $c->getDefinitions();
        $this->assertArrayHasKey('foo', $defs);
        $this->assertSame($factory, $defs['foo']);
    }

    /* -----------------------------------------------------------------
     *  Circular dependency in factory definition
     * --------------------------------------------------------------- */

    #[Test]
    public function detects_circular_in_factory(): void
    {
        $c = new Container([
            'a' => fn(ContainerInterface $c) => $c->get('b'),
            'b' => fn(ContainerInterface $c) => $c->get('a'),
        ]);

        $this->expectException(ServiceResolveException::class);
        $c->get('a');
    }
}
