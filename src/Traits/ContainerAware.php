<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Traits;

use MonkeysLegion\DI\Container;
use Psr\Container\ContainerInterface;

trait ContainerAware
{
    protected static ?ContainerInterface $container = null;

    public static function setContainer(ContainerInterface $container): void
    {
        static::$container = $container;
    }

    protected function container(): ContainerInterface
    {
        return static::$container ?? Container::instance();
    }

    protected function resetContainer(): void
    {
        static::$container = null;
    }

    protected function resolve(string $id): mixed
    {
        return $this->container()->get($id);
    }

    protected function has(string $id): bool
    {
        return $this->container()->has($id);
    }
}