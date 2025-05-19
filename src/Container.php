<?php

declare(strict_types=1);

namespace MonkeysLegion\DI;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use ReflectionException;

final class Container implements ContainerInterface
{
    /** @var array<string, callable|object> */
    private array $definitions = [];

    /** @internal */
    public function __construct(array $defs) { $this->definitions = $defs; }

    /**
     * Register a service definition.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    /**
     * Get a service instance.
     *
     * @param string $id
     * @return mixed
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface|ReflectionException
     */
    public function get(string $id): mixed
    {
        /* ---------------- already registered? ---------------- */
        if (isset($this->definitions[$id])) {
            $entry = $this->definitions[$id];

            // lazy factory → build once, memoise
            if (\is_callable($entry)) {
                $this->definitions[$id] = $entry = $entry($this);
            }

            if (\is_object($entry)) {
                return $entry;
            }

            throw new class("Invalid service $id") extends \RuntimeException
                implements ContainerExceptionInterface {};
        }

        /* -------------- fallback: auto-wire class ------------- */
        if (\class_exists($id)) {
            $ref  = new \ReflectionClass($id);

            // if the constructor has no mandatory params, just `new` it
            if (! $ref->getConstructor()
                || $ref->getConstructor()->getNumberOfRequiredParameters() === 0) {

                $instance = $ref->newInstance();
                $this->definitions[$id] = $instance;      // memoise
                return $instance;
            }

            // ↑ for real projects you’d implement full reflection auto-wiring here
            throw new class(
                "Service $id needs constructor params – register a factory definition."
            ) extends \RuntimeException implements ContainerExceptionInterface {};
        }

        /* -------------- nothing matched – PSR-11 error -------- */
        throw new class("Service $id not found") extends \RuntimeException
            implements NotFoundExceptionInterface {};
    }
}