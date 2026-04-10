<?php
declare(strict_types=1);

namespace MonkeysLegion\DI\Traits;

use MonkeysLegion\DI\Container;

use Psr\Container\ContainerInterface;

/**
 * MonkeysLegion Framework — DI Package
 *
 * Provides convenient access to the global container instance.
 *
 * Use this trait sparingly — constructor injection is always preferred.
 * This exists for edge cases where DI cannot reach (e.g., framework
 * bootstrapping, legacy integration).
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
trait ContainerAware
{
    /**
     * Get the global container instance.
     */
    protected function container(): Container
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