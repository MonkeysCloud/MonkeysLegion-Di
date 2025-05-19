<?php
declare(strict_types=1);

namespace MonkeysLegion\DI;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use ReflectionClass;
use ReflectionParameter;
use ReflectionNamedType;
use ReflectionUnionType;
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
        // the container can resolve itself
        $this->instances[ContainerInterface::class] = $this;
    }

    /* ---------------------------------------------------------------------
     * PSR-11 API
     * ------------------------------------------------------------------- */

    public function has(string $id): bool
    {
        // already built?
        if (isset($this->instances[$id])) {
            return true;
        }

        // explicit factory or instance?
        if (array_key_exists($id, $this->definitions)) {
            return true;
        }

        // fallback: only auto-wire *concrete* classes (not interfaces, not abstracts)
        if (class_exists($id)) {
            $ref = new ReflectionClass($id);
            return $ref->isInstantiable();
        }

        return false;
    }

    public function get(string $id): mixed
    {
        // 1) already built?
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // 2) user definition?
        if (array_key_exists($id, $this->definitions)) {
            return $this->instances[$id]
                = $this->resolveDefinition($id, $this->definitions[$id]);
        }

        // 3) auto-wire a concrete class?
        if (class_exists($id)) {
            return $this->instances[$id] = $this->autoWire($id);
        }

        // 4) nothing matched – PSR-11 error
        throw new class("Service \"{$id}\" not found") extends RuntimeException
            implements NotFoundExceptionInterface {};
    }

    /* ---------------------------------------------------------------------
     * Internal helpers
     * ------------------------------------------------------------------- */

    private function resolveDefinition(string $id, callable|object $def): object
    {
        if (is_callable($def)) {
            if (isset($this->resolving[$id])) {
                throw new class("Circular dependency while resolving \"{$id}\"")
                    extends RuntimeException implements ContainerExceptionInterface {};
            }
            $this->resolving[$id] = true;
            $obj = $def($this);
            unset($this->resolving[$id]);
        } else {
            $obj = $def;
        }

        if (!is_object($obj)) {
            throw new class("Factory for \"{$id}\" did not return an object")
                extends RuntimeException implements ContainerExceptionInterface {};
        }

        return $obj;
    }

    private function autoWire(string $class): object
    {
        $ref = new ReflectionClass($class);

        if (!$ref->isInstantiable()) {
            throw new class("Class {$class} cannot be instantiated")
                extends RuntimeException implements ContainerExceptionInterface {};
        }

        $ctor = $ref->getConstructor();
        if (!$ctor) {
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
        if (!$type || $type instanceof ReflectionUnionType) {
            $this->fail($class, $p);
        }
        /** @var ReflectionNamedType $named */
        $named = $type;
        $name  = $named->getName();

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
        $n    = $p->getName();
        $type = $p->getType()?->getName() ?? 'mixed';
        throw new class("Cannot resolve parameter \${$n} ({$type}) for {$class}")
            extends RuntimeException implements ContainerExceptionInterface {};
    }
}