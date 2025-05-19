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
 *  • **Auto-wiring**: any class without an explicit definition is built
 *    through reflection; constructor parameters are pulled from the
 *    container recursively.
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

        // The container can resolve itself
        $this->instances[ContainerInterface::class] = $this;
    }

    /* ---------------------------------------------------------------------
     * PSR-11 API
     * ------------------------------------------------------------------- */

    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || array_key_exists($id, $this->definitions)
            || class_exists($id);
    }

    public function get(string $id): mixed
    {
        /* -------- already built? -------- */
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        /* -------- user definition? ------ */
        if (array_key_exists($id, $this->definitions)) {
            return $this->instances[$id] = $this->resolveDefinition($id, $this->definitions[$id]);
        }

        /* -------- auto-wire class ------- */
        if (class_exists($id)) {
            return $this->instances[$id] = $this->autoWire($id);
        }

        /* -------- no match :-( ---------- */
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
                // A → B → A …  recursion detected
                throw new class("Circular dependency while resolving \"{$id}\"")
                    extends RuntimeException implements ContainerExceptionInterface {};
            }

            $this->resolving[$id] = true;
            $obj = $def($this);           // call factory
            unset($this->resolving[$id]);
        } else {
            $obj = $def;                  // plain instance
        }

        if (!is_object($obj)) {
            throw new class("Factory for \"{$id}\" did not return an object")
                extends RuntimeException implements ContainerExceptionInterface {};
        }

        return $obj;
    }

    /**
     * Build an object by reflecting its constructor and recursively resolving
     * all its **typed** parameters from the container.
     *
     * @throws ContainerExceptionInterface
     */
    private function autoWire(string $class): object
    {
        $ref = new ReflectionClass($class);

        if (!$ref->isInstantiable()) {
            throw new class("Class {$class} cannot be instantiated")
                extends RuntimeException implements ContainerExceptionInterface {};
        }

        $ctor = $ref->getConstructor();
        if (!$ctor) {
            return $ref->newInstance();           // no dependencies
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $args[] = $this->resolveParameter($class, $param);
        }

        return $ref->newInstanceArgs($args);
    }

    /**
     * Figure out what to inject into one constructor parameter.
     *
     * @throws ContainerExceptionInterface
     */
    private function resolveParameter(string $class, ReflectionParameter $p): mixed
    {
        $type = $p->getType();

        // skip union / scalar / untyped – not safe to auto-resolve
        if (!$type || $type instanceof ReflectionUnionType) {
            $this->fail($class, $p);
        }

        /** @var ReflectionNamedType $type */
        $typeName = $type->getName();

        // special-case: ask for the container itself
        if ($typeName === ContainerInterface::class) {
            return $this;
        }

        if ($this->has($typeName)) {
            return $this->get($typeName);
        }

        if ($p->isDefaultValueAvailable()) {
            return $p->getDefaultValue();
        }

        $this->fail($class, $p);
    }

    private function fail(string $class, ReflectionParameter $p): never
    {
        $name = $p->getName();
        $type = $p->getType()?->getName() ?? 'mixed';

        throw new class(
            "Cannot resolve parameter \${$name} ({$type}) for {$class}"
        ) extends RuntimeException implements ContainerExceptionInterface {};
    }
}