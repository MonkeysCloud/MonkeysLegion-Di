<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Contracts;

use MonkeysLegion\DI\ContainerBuilder;

/**
 * Contract for package service providers.
 *
 * Packages implement this to register their interface-to-concrete
 * bindings and service definitions with the DI container.
 */
interface ServiceProviderInterface
{
    /**
     * Register bindings and definitions.
     */
    public function register(ContainerBuilder $builder): void;
}
