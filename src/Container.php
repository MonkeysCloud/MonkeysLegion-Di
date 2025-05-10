<?php

declare(strict_types=1);

namespace MonkeysLegion\DI;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

final class Container implements ContainerInterface
{
    /** @var array<string, callable|object> */
    private array $definitions = [];

    /** @internal */
    public function __construct(array $defs) { $this->definitions = $defs; }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function get(string $id): mixed
    {
        if (! $this->has($id)) {
            throw new class("Service $id not found") extends \RuntimeException
                implements NotFoundExceptionInterface {};
        }

        $entry = $this->definitions[$id];

        // Lazy factory (callable) → invoke once, memoise result
        if (is_callable($entry)) {
            $this->definitions[$id] = $entry = $entry($this);
        }

        if (is_object($entry)) {
            return $entry;
        }

        throw new class("Invalid service $id") extends \RuntimeException
            implements ContainerExceptionInterface {};
    }
}