<?php
declare(strict_types=1);

namespace MonkeysLegion\DI;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use ReflectionClass;
use ReflectionParameter;
use ReflectionNamedType;
use RuntimeException;

/**
 * Tiny yet powerful PSR-11 container
 * ==================================
 *
 *  • **Factories or instances** can be defined up-front (lazy factories are
 *    invoked once and then memoised).
 *  • **Auto-wiring**: any concrete, instantiable class without an explicit
 *    definition is built through reflection; constructor parameters are
 *    pulled from the container recursively.
 *  • **Circular-dependency protection** with human-readable errors.
 *  • **100 % PSR-11 compliant** – you can swap it for any other container.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable|object> */
    private array $definitions;

    /** @var array<string, object> resolved singletons */
    private array $instances = [];

    /** @var array<string, true> flag-set while a service is being resolved */
    private array $resolving = [];

    public function __construct(array $definitions = [])
    {
        $this->definitions = $definitions;
        // allow retrieving the container itself
        $this->instances[ContainerInterface::class] = $this;
    }

    /* ---------------------------------------------------------------------
     * PSR-11 API
     * ------------------------------------------------------------------- */

    public function has(string $id): bool
    {
        if (isset($this->instances[$id])) {
            return true;
        }
        if (array_key_exists($id, $this->definitions)) {
            return true;
        }
        if (class_exists($id)) {
            $ref = new ReflectionClass($id);
            return $ref->isInstantiable();
        }
        return false;
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (array_key_exists($id, $this->definitions)) {
            return $this->instances[$id] = $this->resolveDefinition($id, $this->definitions[$id]);
        }
        if (class_exists($id)) {
            return $this->instances[$id] = $this->autoWire($id);
        }
        throw new class("Service \"{$id}\" not found") extends RuntimeException implements NotFoundExceptionInterface {};
    }

    /* ---------------------------------------------------------------------
     * Internal helpers
     * ------------------------------------------------------------------- */

    private function resolveDefinition(string $id, callable|object $def): object
    {
        if (is_callable($def)) {
            if (isset($this->resolving[$id])) {
                throw new class("Circular dependency resolving \"{$id}\"")
                    extends RuntimeException implements ContainerExceptionInterface {};
            }
            $this->resolving[$id] = true;
            $obj = $def($this);
            unset($this->resolving[$id]);
        } else {
            $obj = $def;
        }

        if (! is_object($obj)) {
            throw new class("Factory for \"{$id}\" did not return an object")
                extends RuntimeException implements ContainerExceptionInterface {};
        }

        return $obj;
    }

    private function autoWire(string $class): object
    {
        $ref = new ReflectionClass($class);
        if (! $ref->isInstantiable()) {
            throw new class("Class {$class} cannot be instantiated")
                extends RuntimeException implements ContainerExceptionInterface {};
        }
        $ctor = $ref->getConstructor();
        if (! $ctor) {
            return $ref->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $args[] = $this->resolveParameter($class, $param);
        }

        return $ref->newInstanceArgs($args);
    }

    private function resolveParameter(string $class, ReflectionParameter $p): mixed
    {
        $type = $p->getType();
        // only accept a single named class/interface type
        if (! $type instanceof ReflectionNamedType) {
            $this->fail($class, $p);
        }

        $name = $type->getName();

        // special-case: container itself
        if ($name === ContainerInterface::class) {
            return $this;
        }

        if ($this->has($name)) {
            return $this->get($name);
        }

        if ($p->isDefaultValueAvailable()) {
            return $p->getDefaultValue();
        }

        $this->fail($class, $p);
    }

    private function fail(string $class, ReflectionParameter $p): never
    {
        $paramName = '$' . $p->getName();
        $type = $p->getType();
        $typeDesc = $type instanceof ReflectionNamedType
            ? $type->getName()
            : ($type ? (string)$type : 'mixed');
        throw new class("Cannot resolve parameter {$paramName} ({$typeDesc}) for {$class}")
            extends RuntimeException implements ContainerExceptionInterface {};
    }
}