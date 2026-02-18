<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Tests\Unit;

use MonkeysLegion\DI\Container;
use MonkeysLegion\DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class ContainerBuilderTest extends TestCase
{
    #[Test]
    public function build_returns_container(): void
    {
        $builder = new ContainerBuilder();
        $container = $builder->build();

        $this->assertInstanceOf(Container::class, $container);
        $this->assertInstanceOf(ContainerInterface::class, $container);
    }

    #[Test]
    public function add_definitions_merges_into_container(): void
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            'foo' => fn() => new StubNoConstructor(),
        ]);

        $container = $builder->build();
        $this->assertTrue($container->has('foo'));
        $this->assertInstanceOf(StubNoConstructor::class, $container->get('foo'));
    }

    #[Test]
    public function add_definitions_does_not_overwrite_existing(): void
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            'foo' => fn() => 'first',
        ]);
        // += does not overwrite existing keys
        $builder->addDefinitions([
            'foo' => fn() => 'second',
        ]);

        $container = $builder->build();
        $this->assertSame('first', $container->get('foo'));
    }

    #[Test]
    public function set_registers_single_definition(): void
    {
        $builder = new ContainerBuilder();
        $builder->set('bar', fn() => new StubNoConstructor());

        $container = $builder->build();
        $this->assertTrue($container->has('bar'));
    }

    #[Test]
    public function set_overwrites_existing(): void
    {
        $builder = new ContainerBuilder();
        $builder->set('x', fn() => 'first');
        $builder->set('x', fn() => 'second');

        $container = $builder->build();
        $this->assertSame('second', $container->get('x'));
    }

    #[Test]
    public function bind_maps_interface_to_concrete(): void
    {
        $builder = new ContainerBuilder();
        $builder->bind(StubInterface::class, StubConcrete::class);

        $container = $builder->build();
        $this->assertTrue($container->has(StubInterface::class));
        $this->assertInstanceOf(StubConcrete::class, $container->get(StubInterface::class));
    }

    #[Test]
    public function tag_applies_to_built_container(): void
    {
        $builder = new ContainerBuilder();
        $builder->tag(StubNoConstructor::class, 'my.tag');

        $container = $builder->build();
        $tagged = $container->getTagged('my.tag');
        $this->assertCount(1, $tagged);
    }

    #[Test]
    public function transient_applies_to_built_container(): void
    {
        $builder = new ContainerBuilder();
        $builder->transient(StubNoConstructor::class);

        $container = $builder->build();
        $a = $container->get(StubNoConstructor::class);
        $b = $container->get(StubNoConstructor::class);
        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function fluent_api_returns_self(): void
    {
        $builder = new ContainerBuilder();

        $this->assertSame($builder, $builder->addDefinitions([]));
        $this->assertSame($builder, $builder->set('x', fn() => null));
        $this->assertSame($builder, $builder->bind('a', 'b'));
        $this->assertSame($builder, $builder->tag('x', 'tag'));
        $this->assertSame($builder, $builder->transient('x'));
        $this->assertSame($builder, $builder->enableCompilation(sys_get_temp_dir()));
    }

    #[Test]
    public function enable_compilation_with_no_cache_still_works(): void
    {
        $cacheDir = sys_get_temp_dir() . '/di_test_' . uniqid('', true);

        $builder = new ContainerBuilder();
        $builder->enableCompilation($cacheDir);
        $builder->set('test', fn() => new StubNoConstructor());

        $container = $builder->build();

        // No compiled file exists, so it falls back to regular Container
        $this->assertInstanceOf(Container::class, $container);
        $this->assertTrue($container->has('test'));
    }
}
