<?php

declare(strict_types=1);

namespace MonkeysLegion\DI;

use MonkeysLegion\DI\Attributes\Inject;
use MonkeysLegion\DI\Attributes\Tagged;
use MonkeysLegion\DI\Attributes\Transient;
use MonkeysLegion\DI\Exceptions\ServiceNotFoundException;
use MonkeysLegion\DI\Exceptions\ServiceResolveException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionParameter;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Production-ready PSR-11 container (v1.1).
 *
 *  • Factories / instances can be defined up-front (lazy factories are memoised).
 *  • Auto-wiring for any concrete class without an explicit definition.
 *  • Circular-dependency protection with human-readable errors.
 *  • Union-type aware (Foo|Bar, ?Foo) and 100 % PSR-11 compliant.
 *  • #[Inject], #[Transient], #[Tagged] attribute support.
 *  • Interface-to-concrete binding via bind().
 *  • Service tagging with getTagged().
 *  • Runtime set() for post-construction definitions.
 *  • reset() for testing scenarios.
 */
class Container implements ContainerInterface
{
    /** @var array<string, callable|object> */
    protected array $definitions;

    /** @var array<string, object> resolved singletons */
    protected array $instances = [];

    /** @var array<string, true> flags while a service is being resolved */
    private array $resolving = [];

    /** @var array<string, string> interface → concrete class bindings */
    private array $bindings = [];

    /** @var array<string, list<string>> tag → list of service IDs */
    private array $tags = [];

    /** @var array<string, true> IDs marked as transient */
    private array $transients = [];

    public function __construct(array $definitions = [])
    {
        $this->definitions = $definitions;
        $this->instances[ContainerInterface::class] = $this;   // allow "ContainerInterface" injection
        $this->instances[self::class] = $this;
    }

    /* ---------------------------------------------------------------------
     *  PSR-11 API
     * ------------------------------------------------------------------- */

    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || array_key_exists($id, $this->definitions)
            || isset($this->bindings[$id])
            || (class_exists($id) && (new ReflectionClass($id))->isInstantiable());
    }

    public function get(string $id): mixed
    {
        // 1. Already resolved singleton
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // 2. Interface binding — redirect to concrete
        if (isset($this->bindings[$id])) {
            $concrete = $this->bindings[$id];
            $result = $this->get($concrete);

            if (!isset($this->transients[$id])) {
                $this->instances[$id] = $result;
            }
            return $result;
        }

        // 3. Explicit definition (factory or instance)
        if (array_key_exists($id, $this->definitions)) {
            $result = $this->resolveDefinition($id, $this->definitions[$id]);

            if (!isset($this->transients[$id])) {
                $this->instances[$id] = $result;
            }
            return $result;
        }

        // 4. Auto-wire concrete class
        if (class_exists($id)) {
            $result = $this->autoWire($id);

            if (!isset($this->transients[$id])) {
                $this->instances[$id] = $result;
            }
            return $result;
        }

        throw new ServiceNotFoundException("Service \"{$id}\" not found");
    }

    /* ---------------------------------------------------------------------
     *  Runtime registration API (v1.1)
     * ------------------------------------------------------------------- */

    /**
     * Register a service definition at runtime.
     *
     * @param string          $id  Service identifier (class name or alias)
     * @param callable|object $definition Factory closure or pre-built instance
     */
    public function set(string $id, callable|object $definition): void
    {
        $this->definitions[$id] = $definition;
        unset($this->instances[$id]); // invalidate cached instance
    }

    /**
     * Bind an abstract (interface) to a concrete implementation class.
     *
     * When the abstract ID is requested via get(), the container resolves
     * the concrete class instead.
     */
    public function bind(string $abstract, string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
        unset($this->instances[$abstract]); // invalidate cached instance
    }

    /**
     * Tag a service ID with one or more tags for aggregation.
     *
     * @param string          $id   Service identifier
     * @param string|string[] $tags One or more tag names
     */
    public function tag(string $id, string|array $tags): void
    {
        foreach ((array) $tags as $tag) {
            $this->tags[$tag][] = $id;
        }
    }

    /**
     * Mark a service ID as transient (new instance on every get() call).
     */
    public function transient(string $id): void
    {
        $this->transients[$id] = true;
        unset($this->instances[$id]);
    }

    /**
     * Retrieve all services marked with the given tag.
     *
     * @return list<mixed>
     */
    public function getTagged(string $tag): array
    {
        $services = [];
        foreach ($this->tags[$tag] ?? [] as $id) {
            $services[] = $this->get($id);
        }
        return $services;
    }

    /**
     * Clear all resolved instances (useful for testing).
     *
     * Definitions and bindings are preserved — only cached singletons are
     * evicted, forcing re-resolution on next get().
     */
    public function reset(): void
    {
        $self = $this->instances[ContainerInterface::class] ?? null;
        $this->instances = [];
        if ($self !== null) {
            $this->instances[ContainerInterface::class] = $self;
            $this->instances[self::class] = $self;
        }
    }

    /**
     * Get all registered definitions (for dumping / introspection).
     *
     * @return array<string, callable|object>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /* ---------------------------------------------------------------------
     *  Definition / auto-wiring helpers
     * ------------------------------------------------------------------- */

    private function resolveDefinition(string $id, callable|object $def): mixed
    {
        if (is_callable($def)) {
            if (isset($this->resolving[$id])) {
                throw new ServiceResolveException("Circular dependency while resolving \"{$id}\"");
            }
            $this->resolving[$id] = true;

            try {
                $result = $def($this);
            } finally {
                unset($this->resolving[$id]);
            }

            return $result;
        }

        // Pre-built instance
        return $def;
    }

    private function autoWire(string $class): object
    {
        $ref = new ReflectionClass($class);

        if (!$ref->isInstantiable()) {
            throw new ServiceResolveException("Class {$class} cannot be instantiated");
        }

        // Detect #[Transient] attribute on the class
        if ($ref->getAttributes(Transient::class)) {
            $this->transients[$class] = true;
        }

        // Detect #[Tagged] attributes on the class
        $tagAttrs = $ref->getAttributes(Tagged::class);
        foreach ($tagAttrs as $tagAttr) {
            /** @var Tagged $tag */
            $tag = $tagAttr->newInstance();
            if (!in_array($class, $this->tags[$tag->tag] ?? [], true)) {
                $this->tags[$tag->tag][] = $class;
            }
        }

        // Guard circular dependencies
        if (isset($this->resolving[$class])) {
            throw new ServiceResolveException("Circular dependency while resolving \"{$class}\"");
        }
        $this->resolving[$class] = true;

        try {
            $ctor = $ref->getConstructor();
            if (!$ctor) {
                return $ref->newInstance();
            }

            $args = [];
            foreach ($ctor->getParameters() as $p) {
                $args[] = $this->resolveParameter($class, $p);
            }

            return $ref->newInstanceArgs($args);
        } finally {
            unset($this->resolving[$class]);
        }
    }

    /* ---------------------------------------------------------------------
     *  Parameter resolution (union-type aware + #[Inject] support)
     * ------------------------------------------------------------------- */

    private function resolveParameter(string $class, ReflectionParameter $p): mixed
    {
        // Check for #[Inject] attribute override
        $injectAttrs = $p->getAttributes(Inject::class);
        if ($injectAttrs) {
            /** @var Inject $inject */
            $inject = $injectAttrs[0]->newInstance();
            return $this->get($inject->id);
        }

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

            if ($p->isDefaultValueAvailable()) {
                return $p->getDefaultValue();
            }

            // nullable like ?Foo — use default value (often NULL) when allowed
            if ($p->allowsNull() && $p->isDefaultValueAvailable()) {
                return $p->getDefaultValue();
            }
        }

        /* ---------- 3) no type / built-in with default ------------------- */
        if ($p->isDefaultValueAvailable()) {
            return $p->getDefaultValue();
        }

        /* ---------- 4) anything else → fail ----------------------------- */
        $this->fail($class, $p);
    }

    private function resolveNamed(ReflectionNamedType $type,
                                  string $class,
                                  ReflectionParameter $p): mixed
    {
        $id = $type->getName();

        if ($id === ContainerInterface::class || $id === self::class) {
            return $this;
        }

        if (!$type->isBuiltin() && $this->has($id)) {
            return $this->get($id);
        }

        if ($p->isDefaultValueAvailable()) {
            return $p->getDefaultValue();
        }

        if ($type->allowsNull()) {
            return null;
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

        throw new ServiceResolveException(
            "Cannot resolve constructor parameter {$param} ({$typeStr}) for {$class}"
        );
    }
}