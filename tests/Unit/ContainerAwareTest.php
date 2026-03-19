<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Tests\Unit;

use MonkeysLegion\DI\Container;
use MonkeysLegion\DI\Traits\ContainerAware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class ContainerAwareStub
{
    use ContainerAware;

    public function testResolve(string $id): mixed
    {
        return $this->resolve($id);
    }

    public function testHas(string $id): bool
    {
        return $this->has($id);
    }

    public function testContainer(): ContainerInterface
    {
        return $this->container();
    }
}

class ContainerAwareTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    #[Test]
    public function it_proxies_directly_to_the_global_container_instance(): void
    {
        $c = new Container();
        Container::setInstance($c);

        $stub = new ContainerAwareStub();
        $this->assertSame($c, $stub->testContainer());
    }

    #[Test]
    public function it_checks_if_service_exists_in_global_container(): void
    {
        $c = new Container(['foo' => fn() => 'bar']);
        Container::setInstance($c);

        $stub = new ContainerAwareStub();
        $this->assertTrue($stub->testHas('foo'));
        $this->assertFalse($stub->testHas('bar'));
    }

    #[Test]
    public function it_resolves_service_from_global_container(): void
    {
        $c = new Container(['foo' => fn() => 'bar']);
        Container::setInstance($c);

        $stub = new ContainerAwareStub();
        $this->assertSame('bar', $stub->testResolve('foo'));
    }
}
