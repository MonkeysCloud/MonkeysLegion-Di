<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Tests\Unit;

use MonkeysLegion\DI\Container;
use MonkeysLegion\DI\Exceptions\ServiceResolveException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerStaticInstanceTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    #[Test]
    public function set_instance_stores_global_instance(): void
    {
        $c = new Container();
        Container::setInstance($c);

        $this->assertSame($c, Container::instance());
    }

    #[Test]
    public function instance_throws_exception_if_not_set(): void
    {
        $this->expectException(ServiceResolveException::class);
        $this->expectExceptionMessage('Container instance not set');

        Container::instance();
    }

    #[Test]
    public function reset_instance_clears_global_instance(): void
    {
        $c = new Container();
        Container::setInstance($c);
        $this->assertSame($c, Container::instance());

        Container::resetInstance();

        $this->expectException(ServiceResolveException::class);
        Container::instance();
    }
}
