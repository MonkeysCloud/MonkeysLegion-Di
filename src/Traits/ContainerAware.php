<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Traits;

use MonkeysLegion\DI\Container;

trait ContainerAware
{
    protected function resolve(string $id): mixed
    {
        return Container::instance()->get($id);
    }

    protected function has(string $id): bool
    {
        return Container::instance()->has($id);
    }
}