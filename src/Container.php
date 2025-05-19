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
 * Tiny yet powerful PSR-11 container.
 *
 *  • Factories / instances can be defined up-front (lazy factories are
 *    memoised).
 *  • Auto-wiring for any concrete class without an explicit definition.
 *  • Circular-dependency protection with human-readable errors.
 *  • Union-type aware (Foo|Bar, ?Foo) and 100 % PSR-11 compliant.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable|object> */
    private array $definitions;

    /** @var array<string, object> resolved singletons */
    private array $instances = [];

    /** @var array<string, true> flags while a service is being resolved */
    private array $resolving = [];

    public function __construct(array $definitions = [])
    {
        $this->definitions = $definitions;
        $this->instances[ContainerInterface::class] = $this;   // allow “ContainerInterface” injection
    }

    /* ---------------------------------------------------------------------
     *  PSR-11 API
     * ------------------------------------------------------------------- */

    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || array_key_exists($id, $this->definitions)
            || (class_exists($id) && (new ReflectionClass($id))->isInstantiable());
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

        throw new class("Service \"{$id}\" not found")
            extends RuntimeException implements NotFoundExceptionInterface {};
    }

    /* ---------------------------------------------------------------------
     *  Definition / auto-wiring helpers
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
        foreach ($ctor->getParameters() as $p) {
            $args[] = $this->resolveParameter($class, $p);
        }

        return $ref->newInstanceArgs($args);
    }

    /* ---------------------------------------------------------------------
     *  Parameter resolution (union-type aware)
     * ------------------------------------------------------------------- */

    private function resolveParameter(string $class, ReflectionParameter $p): mixed
    {
        $type = $p->getType();

        /* ---------- 1) single named class / interface ------------------- */
        if ($type instanceof ReflectionNamedType) {
            return $this->resolveNamed($type, $class, $p);
        }

        /* ---------- 2) union type (Foo|Bar|Baz / ?Foo) ------------------ */
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof ReflectionNamedType && !$inner->isBuiltin()) {
                    $id = $inner->getName();
                    if ($this->has($id)) {
                        return $this->get($id);
                    }
                }
            }

            // nullable like ?Foo – use default value (often NULL) when allowed
            if ($p->allowsNull() && $p->isDefaultValueAvailable()) {
                return $p->getDefaultValue();
            }
        }

        /* ---------- 3) anything else → fail ----------------------------- */
        $this->fail($class, $p);
    }

    private function resolveNamed(ReflectionNamedType $type,
                                  string $class,
                                  ReflectionParameter $p): mixed
    {
        $id = $type->getName();

        if ($id === ContainerInterface::class) {
            return $this;
        }

        if ($this->has($id)) {
            return $this->get($id);
        }

        if ($p->isDefaultValueAvailable()) {
            return $p->getDefaultValue();
        }

        $this->fail($class, $p);
    }

    /* ---------------------------------------------------------------------
     *  Error helper
     * ------------------------------------------------------------------- */

    private function fail(string $class, ReflectionParameter $p): never
    {
        $param   = '$' . $p->getName();
        $typeStr = (string) $p->getType() ?: 'mixed';

        throw new class(
            "Cannot resolve constructor parameter {$param} ({$typeStr}) for {$class}"
        ) extends RuntimeException implements ContainerExceptionInterface {};
    }
}