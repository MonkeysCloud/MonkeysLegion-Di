<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Traits;

use MonkeysLegion\DI\Container;
use Psr\Container\ContainerInterface;

trait ContainerAware
{
    /**
     * Get the global container instance.
     */
    protected function container(): ContainerInterface
    {
        return Container::instance();
    }

    /**
     * Resolve a service from the global container.
     */
    protected function resolve(string $id): mixed
    {
        return $this->container()->get($id);
    }

    /**
     * Check if a service exists in the global container.
     */
    protected function has(string $id): bool
    {
        return $this->container()->has($id);
    }
}