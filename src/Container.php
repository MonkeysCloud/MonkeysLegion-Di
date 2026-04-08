<?php
declare(strict_types=1);

namespace MonkeysLegion\DI;

use MonkeysLegion\DI\Attributes\Alias;
use MonkeysLegion\DI\Attributes\Decorator;
use MonkeysLegion\DI\Attributes\Factory;
use MonkeysLegion\DI\Attributes\Inject;
use MonkeysLegion\DI\Attributes\Lazy;
use MonkeysLegion\DI\Attributes\Tagged;
use MonkeysLegion\DI\Attributes\Transient;
use MonkeysLegion\DI\Contracts\ContainerInterface;
use MonkeysLegion\DI\Exceptions\CircularDependencyException;
use MonkeysLegion\DI\Exceptions\ServiceNotFoundException;
use MonkeysLegion\DI\Exceptions\ServiceResolveException;

use Psr\Container\ContainerInterface as PsrContainerInterface;

use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * MonkeysLegion Framework — DI Package
 *
 * Production-ready PSR-11 container with attribute-first architecture.
 *
 *  • Factories / instances can be defined up-front (lazy factories are memoised).
 *  • Auto-wiring for any concrete class without an explicit definition.
 *  • Circular-dependency protection with human-readable dependency chains.
 *  • Union-type aware (Foo|Bar, ?Foo) and 100 % PSR-11 compliant.
 *  • #[Inject], #[Transient], #[Tagged], #[Lazy], #[Factory], #[Alias], #[Decorator] support.
 *  • Interface-to-concrete binding via bind().
 *  • Contextual binding via contextual().
 *  • Method injection via call().
 *  • Transient resolution via make().
 *  • Service aliasing via alias().
 *  • Reflection caching for maximum performance.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
class Container implements ContainerInterface
{
    // ── Properties ─────────────────────────────────────────────────

    /** @var array<string, callable|object> */
    protected array $definitions;

    /** @var array<string, object> Resolved singletons. */
    protected array $instances = [];

    /** @var list<string> Current resolution stack for circular detection. */
    private array $resolvingStack = [];

    /** @var array<string, true> Flags while a service is being resolved. */
    private array $resolving = [];

    /** @var array<string, string> Interface → concrete class bindings. */
    private array $bindings = [];

    /** @var array<string, list<string>> Tag → list of service IDs. */
    private array $tags = [];

    /** @var array<string, true> IDs marked as transient. */
    private array $transients = [];

    /** @var array<string, string> Alias → target service ID. */
    private array $aliases = [];

    /** @var array<string, array<string, string|callable>> Consumer → [abstract → concrete]. */
    private array $contextualBindings = [];

    /** @var array<string, array{ref: ReflectionClass<object>, params: list<ReflectionParameter>, attrs: array<string, object>}> */
    private static array $reflectionCache = [];

    private static ?self $instance = null;

    // ── Constructor ────────────────────────────────────────────────

    /**
     * @param array<string, callable|object> $definitions Initial service definitions.
     */
    public function __construct(array $definitions = [])
    {
        $this->definitions = $definitions;
        $this->instances[PsrContainerInterface::class] = $this;
        $this->instances[ContainerInterface::class]     = $this;
        $this->instances[self::class]                   = $this;
    }

    // ── Static Instance ────────────────────────────────────────────

    public static function setInstance(self $container): void
    {
        self::$instance = $container;
    }

    public static function instance(): self
    {
        if (!self::$instance) {
            throw new ServiceResolveException(
                'Container instance not set. Call Container::setInstance() during application bootstrap.',
            );
        }

        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // ── PSR-11 API ─────────────────────────────────────────────────

    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || isset($this->aliases[$id])
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

        // 2. Alias — resolve the target
        if (isset($this->aliases[$id])) {
            return $this->get($this->aliases[$id]);
        }

        // 3. Interface binding — redirect to concrete
        if (isset($this->bindings[$id])) {
            return $this->resolveBinding($id);
        }

        // 4. Explicit definition (factory or instance)
        if (array_key_exists($id, $this->definitions)) {
            $result = $this->resolveDefinition($id, $this->definitions[$id]);

            if (!isset($this->transients[$id])) {
                $this->instances[$id] = $result;
            }

            return $result;
        }

        // 5. Auto-wire concrete class
        if (class_exists($id)) {
            // Check if class is marked #[Lazy] — return proxy instead
            if ($this->isClassLazy($id)) {
                $proxy = $this->createLazyProxy($id);

                if (!isset($this->transients[$id])) {
                    $this->instances[$id] = $proxy;
                }

                return $proxy;
            }

            $result = $this->autoWire($id);

            if (!isset($this->transients[$id])) {
                $this->instances[$id] = $result;
            }

            return $result;
        }

        throw new ServiceNotFoundException("Service \"{$id}\" not found");
    }

    // ── Registration API ───────────────────────────────────────────

    /**
     * Register a service definition at runtime.
     *
     * @param string          $id         Service identifier (class name or alias).
     * @param callable|object $definition Factory closure or pre-built instance.
     */
    public function set(string $id, callable|object $definition): void
    {
        $this->definitions[$id] = $definition;
        unset($this->instances[$id]);
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
        unset($this->instances[$abstract]);
    }

    /**
     * Register a contextual binding.
     *
     * "When $consumer needs $abstract, give it $concrete."
     *
     * @param string          $consumer FQCN of the consuming class.
     * @param string          $abstract Interface or class being requested.
     * @param string|callable $concrete Concrete class name or factory closure.
     */
    public function contextual(string $consumer, string $abstract, string|callable $concrete): void
    {
        $this->contextualBindings[$consumer][$abstract] = $concrete;
    }

    /**
     * Register an alias for an existing service ID.
     */
    public function alias(string $alias, string $id): void
    {
        $this->aliases[$alias] = $id;
        unset($this->instances[$alias]);
    }

    /**
     * Tag a service ID with one or more tags for aggregation.
     *
     * @param string          $id   Service identifier.
     * @param string|string[] $tags One or more tag names.
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

    // ── Advanced Resolution API ────────────────────────────────────

    /**
     * Always create a new instance, ignoring singleton cache.
     *
     * @param string              $class  FQCN to resolve.
     * @param array<string,mixed> $params Constructor parameter overrides.
     */
    public function make(string $class, array $params = []): object
    {
        return $this->autoWire($class, $params);
    }

    /**
     * Invoke a callable with auto-resolved parameters (method injection).
     *
     * Supports:
     *  - Closures: fn(LoggerInterface $log) => ...
     *  - Instance methods: [$service, 'method']
     *  - Static methods: [ClassName::class, 'method']
     *  - Invokable objects: $service (has __invoke)
     *
     * @param callable|array<object|string,string> $callable Method, closure, or [class, method].
     * @param array<string,mixed>                  $params   Parameter overrides.
     */
    public function call(callable|array $callable, array $params = []): mixed
    {
        if (is_array($callable) && count($callable) === 2) {
            [$target, $method] = $callable;

            if (is_string($target)) {
                $target = $this->get($target);
            }

            $ref = new ReflectionMethod($target, $method);
            $args = $this->resolveMethodParams($ref, $params);

            return $ref->invokeArgs($target, $args);
        }

        if ($callable instanceof \Closure) {
            $ref = new ReflectionFunction($callable);
            $args = $this->resolveMethodParams($ref, $params);

            return $ref->invokeArgs($args);
        }

        if (is_object($callable) && method_exists($callable, '__invoke')) {
            $ref = new ReflectionMethod($callable, '__invoke');
            $args = $this->resolveMethodParams($ref, $params);

            return $ref->invokeArgs($callable, $args);
        }

        // Fallback — just call it
        return $callable(...$params);
    }

    // ── Reset ──────────────────────────────────────────────────────

    /**
     * Clear all resolved instances (useful for testing).
     *
     * Definitions and bindings are preserved — only cached singletons are
     * evicted, forcing re-resolution on next get().
     */
    public function reset(): void
    {
        $this->instances = [
            PsrContainerInterface::class => $this,
            ContainerInterface::class    => $this,
            self::class                  => $this,
        ];
    }

    /**
     * Clear the static reflection cache (for testing).
     */
    public static function clearReflectionCache(): void
    {
        self::$reflectionCache = [];
    }

    // ── Introspection ──────────────────────────────────────────────

    /**
     * Get all registered definitions (for dumping / introspection).
     *
     * @return array<string, callable|object>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    // ── Private: Binding Resolution ────────────────────────────────

    private function resolveBinding(string $id): mixed
    {
        if (isset($this->resolving[$id])) {
            throw new CircularDependencyException([...$this->resolvingStack, $id]);
        }

        $this->resolving[$id] = true;
        $this->resolvingStack[] = $id;

        try {
            $concrete = $this->bindings[$id];
            $result = $this->get($concrete);
        } finally {
            unset($this->resolving[$id]);
            array_pop($this->resolvingStack);
        }

        if (!isset($this->transients[$id]) && !isset($this->transients[$concrete])) {
            $this->instances[$id] = $result;
        }

        return $result;
    }

    // ── Private: Definition Resolution ─────────────────────────────

    private function resolveDefinition(string $id, callable|object $def): mixed
    {
        if (is_callable($def)) {
            if (isset($this->resolving[$id])) {
                throw new CircularDependencyException([...$this->resolvingStack, $id]);
            }

            $this->resolving[$id] = true;
            $this->resolvingStack[] = $id;

            try {
                $result = $def($this);
            } finally {
                unset($this->resolving[$id]);
                array_pop($this->resolvingStack);
            }

            return $result;
        }

        // Pre-built instance
        return $def;
    }

    // ── Private: Auto-Wiring ───────────────────────────────────────

    /**
     * Auto-wire a concrete class.
     *
     * @param string              $class  FQCN to resolve.
     * @param array<string,mixed> $params Constructor parameter overrides.
     */
    private function autoWire(string $class, array $params = []): object
    {
        $meta = $this->getReflectionMeta($class);
        $ref  = $meta['ref'];

        // Detect #[Factory(method)] before instantiable check — factory
        // classes may have private constructors by design
        if (isset($meta['attrs']['factory'])) {
            return $this->resolveViaFactoryFull($class, $meta);
        }

        if (!$ref->isInstantiable()) {
            throw new ServiceResolveException("Class {$class} cannot be instantiated");
        }

        // Detect #[Transient]
        if (isset($meta['attrs']['transient'])) {
            $this->transients[$class] = true;
        }

        // Detect #[Tagged]
        foreach ($meta['attrs']['tags'] ?? [] as $tag) {
            if (!in_array($class, $this->tags[$tag] ?? [], true)) {
                $this->tags[$tag][] = $class;
            }
        }

        // Detect #[Alias]
        foreach ($meta['attrs']['aliases'] ?? [] as $aliasName) {
            if (!isset($this->aliases[$aliasName])) {
                $this->aliases[$aliasName] = $class;
            }
        }

        // Guard circular dependencies
        if (isset($this->resolving[$class])) {
            throw new CircularDependencyException([...$this->resolvingStack, $class]);
        }

        $this->resolving[$class] = true;
        $this->resolvingStack[] = $class;

        try {

            if ($meta['params'] === []) {
                $instance = $ref->newInstance();
            } else {
                $args = [];
                foreach ($meta['params'] as $p) {
                    if (isset($params[$p->getName()])) {
                        $args[] = $params[$p->getName()];
                    } else {
                        $args[] = $this->resolveParameter($class, $p);
                    }
                }

                $instance = $ref->newInstanceArgs($args);
            }

            // Detect #[Decorator(decorates)]
            if (isset($meta['attrs']['decorator'])) {
                $this->applyDecorator($class, $meta['attrs']['decorator']);
            }

            return $instance;
        } finally {
            unset($this->resolving[$class]);
            array_pop($this->resolvingStack);
        }
    }

    // ── Private: Factory Resolution ────────────────────────────────

    /**
     * Full factory resolution: process class-level attributes + circular guard,
     * then delegate to resolveViaFactory.
     *
     * @param array<string, mixed> $meta Reflection metadata.
     */
    private function resolveViaFactoryFull(string $class, array $meta): object
    {
        // Process class-level attributes
        if (isset($meta['attrs']['transient'])) {
            $this->transients[$class] = true;
        }

        foreach ($meta['attrs']['tags'] ?? [] as $tag) {
            if (!in_array($class, $this->tags[$tag] ?? [], true)) {
                $this->tags[$tag][] = $class;
            }
        }

        foreach ($meta['attrs']['aliases'] ?? [] as $aliasName) {
            if (!isset($this->aliases[$aliasName])) {
                $this->aliases[$aliasName] = $class;
            }
        }

        // Guard circular
        if (isset($this->resolving[$class])) {
            throw new CircularDependencyException([...$this->resolvingStack, $class]);
        }

        $this->resolving[$class] = true;
        $this->resolvingStack[] = $class;

        try {
            return $this->resolveViaFactory($class, $meta['attrs']['factory']);
        } finally {
            unset($this->resolving[$class]);
            array_pop($this->resolvingStack);
        }
    }

    /**
     * Resolve a service via its #[Factory(method)] attribute.
     */
    private function resolveViaFactory(string $class, string $method): object
    {
        $ref = new ReflectionMethod($class, $method);

        if (!$ref->isStatic()) {
            throw new ServiceResolveException(
                "#[Factory] method {$class}::{$method}() must be static",
            );
        }

        $args = $this->resolveMethodParams($ref);

        $result = $ref->invokeArgs(null, $args);

        if (!is_object($result)) {
            throw new ServiceResolveException(
                "#[Factory] method {$class}::{$method}() must return an object",
            );
        }

        return $result;
    }

    // ── Private: Decorator Application ─────────────────────────────

    /**
     * Apply a decorator: bind the decorator class as the implementation
     * for the decorated service, store original for $inner injection.
     */
    private function applyDecorator(string $decoratorClass, string $decoratedId): void
    {
        // The decorator replaces the decorated service
        // The original is available via contextual binding for $inner
        if (isset($this->instances[$decoratedId])) {
            $original = $this->instances[$decoratedId];
            unset($this->instances[$decoratedId]);
        } elseif (isset($this->bindings[$decoratedId])) {
            $original = $this->get($this->bindings[$decoratedId]);
        } else {
            $original = null;
        }

        if ($original !== null) {
            // Store original so the decorator's $inner parameter gets it
            $this->contextualBindings[$decoratorClass][$decoratedId] = fn() => $original;
        }

        $this->bindings[$decoratedId] = $decoratorClass;
    }

    // ── Private: Lazy Proxy Creation ───────────────────────────────

    /**
     * Create a PHP 8.4 native lazy proxy for deferred resolution.
     * The initializer calls autoWire() directly to avoid returning
     * the cached proxy from get().
     */
    private function createLazyProxy(string $class): object
    {
        $ref = new ReflectionClass($class);

        return $ref->newLazyProxy(function (object $proxy) use ($class): object {
            return $this->autoWire($class);
        });
    }

    // ── Private: Parameter Resolution ──────────────────────────────

    /**
     * Resolve a constructor parameter.
     */
    private function resolveParameter(string $class, ReflectionParameter $p): mixed
    {
        // 1. Check for #[Inject] attribute override
        $injectAttrs = $p->getAttributes(Inject::class);
        if ($injectAttrs) {
            /** @var Inject $inject */
            $inject = $injectAttrs[0]->newInstance();
            return $this->get($inject->id);
        }

        // 2. Check for #[Lazy] attribute on parameter
        $isLazy = !empty($p->getAttributes(Lazy::class));

        $type = $p->getType();

        // 3. Single named class / interface
        if ($type instanceof ReflectionNamedType) {
            return $this->resolveNamed($type, $class, $p, $isLazy);
        }

        // 4. Union type (Foo|Bar|Baz / ?Foo)
        if ($type instanceof ReflectionUnionType) {
            return $this->resolveUnion($type, $class, $p);
        }

        // 5. No type / built-in with default
        if ($p->isDefaultValueAvailable()) {
            return $p->getDefaultValue();
        }

        // 6. Fail
        $this->fail($class, $p);
    }

    private function resolveNamed(
        ReflectionNamedType $type,
        string $class,
        ReflectionParameter $p,
        bool $isLazy = false,
    ): mixed {
        $id = $type->getName();

        // Self-injection
        if ($id === PsrContainerInterface::class || $id === ContainerInterface::class || $id === self::class) {
            return $this;
        }

        if (!$type->isBuiltin()) {
            // Check contextual binding first
            if (isset($this->contextualBindings[$class][$id])) {
                $contextual = $this->contextualBindings[$class][$id];
                if (is_callable($contextual)) {
                    /** @var callable $contextual */
                    return $contextual($this);
                }

                return $this->get($contextual);
            }

            // Check #[Lazy] on the target class itself
            if (!$isLazy && $this->isClassLazy($id)) {
                $isLazy = true;
            }

            if ($isLazy && $this->has($id)) {
                return $this->createLazyProxy($id);
            }

            if ($this->has($id)) {
                return $this->get($id);
            }
        }

        if ($p->isDefaultValueAvailable()) {
            return $p->getDefaultValue();
        }

        if ($type->allowsNull()) {
            return null;
        }

        $this->fail($class, $p);
    }

    private function resolveUnion(
        ReflectionUnionType $type,
        string $class,
        ReflectionParameter $p,
    ): mixed {
        foreach ($type->getTypes() as $inner) {
            if ($inner instanceof ReflectionNamedType && !$inner->isBuiltin()) {
                $id = $inner->getName();

                // Check contextual binding
                if (isset($this->contextualBindings[$class][$id])) {
                    $contextual = $this->contextualBindings[$class][$id];
                    if (is_callable($contextual)) {
                        /** @var callable $contextual */
                        return $contextual($this);
                    }

                    return $this->get($contextual);
                }

                if ($this->has($id)) {
                    return $this->get($id);
                }
            }
        }

        if ($p->isDefaultValueAvailable()) {
            return $p->getDefaultValue();
        }

        if ($p->allowsNull()) {
            return null;
        }

        $this->fail($class, $p);
    }

    // ── Private: Method Parameter Resolution ───────────────────────

    /**
     * Resolve parameters for a method or function (used by call() and factory).
     *
     * @param ReflectionMethod|ReflectionFunction $ref
     * @param array<string,mixed>                 $params Override params.
     *
     * @return list<mixed>
     */
    private function resolveMethodParams(
        ReflectionMethod|ReflectionFunction $ref,
        array $params = [],
    ): array {
        $args = [];

        foreach ($ref->getParameters() as $p) {
            $name = $p->getName();

            if (isset($params[$name])) {
                $args[] = $params[$name];
                continue;
            }

            $type = $p->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $id = $type->getName();

                if ($id === PsrContainerInterface::class || $id === ContainerInterface::class || $id === self::class) {
                    $args[] = $this;
                    continue;
                }

                if ($this->has($id)) {
                    $args[] = $this->get($id);
                    continue;
                }
            }

            if ($type instanceof ReflectionUnionType) {
                $resolved = false;
                foreach ($type->getTypes() as $inner) {
                    if ($inner instanceof ReflectionNamedType && !$inner->isBuiltin() && $this->has($inner->getName())) {
                        $args[] = $this->get($inner->getName());
                        $resolved = true;
                        break;
                    }
                }
                if ($resolved) {
                    continue;
                }
            }

            if ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
                continue;
            }

            if ($p->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new ServiceResolveException(
                "Cannot resolve parameter \${$name} for callable",
            );
        }

        return $args;
    }

    // ── Private: Reflection Cache ──────────────────────────────────

    /**
     * Get cached reflection metadata for a class.
     *
     * @return array{ref: ReflectionClass<object>, params: list<ReflectionParameter>, attrs: array<string, mixed>}
     */
    private function getReflectionMeta(string $class): array
    {
        if (isset(self::$reflectionCache[$class])) {
            return self::$reflectionCache[$class];
        }

        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        $params = $ctor?->getParameters() ?? [];

        // Pre-parse class-level attributes
        $attrs = [];

        if ($ref->getAttributes(Transient::class)) {
            $attrs['transient'] = true;
        }

        $tagAttrs = $ref->getAttributes(Tagged::class);
        foreach ($tagAttrs as $tagAttr) {
            /** @var Tagged $tag */
            $tag = $tagAttr->newInstance();
            $attrs['tags'][] = $tag->tag;
        }

        $aliasAttrs = $ref->getAttributes(Alias::class);
        foreach ($aliasAttrs as $aliasAttr) {
            /** @var Alias $alias */
            $alias = $aliasAttr->newInstance();
            $attrs['aliases'][] = $alias->name;
        }

        $factoryAttrs = $ref->getAttributes(Factory::class);
        if ($factoryAttrs) {
            /** @var Factory $factory */
            $factory = $factoryAttrs[0]->newInstance();
            $attrs['factory'] = $factory->method;
        }

        $decoratorAttrs = $ref->getAttributes(Decorator::class);
        if ($decoratorAttrs) {
            /** @var Decorator $decorator */
            $decorator = $decoratorAttrs[0]->newInstance();
            $attrs['decorator'] = $decorator->decorates;
        }

        if ($ref->getAttributes(Lazy::class)) {
            $attrs['lazy'] = true;
        }

        self::$reflectionCache[$class] = [
            'ref'    => $ref,
            'params' => $params,
            'attrs'  => $attrs,
        ];

        return self::$reflectionCache[$class];
    }

    /**
     * Check if a class is marked with #[Lazy] at the class level.
     */
    private function isClassLazy(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $meta = $this->getReflectionMeta($class);

        return isset($meta['attrs']['lazy']);
    }

    // ── Private: Error Helper ──────────────────────────────────────

    private function fail(string $class, ReflectionParameter $p): never
    {
        $param   = '$' . $p->getName();
        $typeStr = (string) $p->getType() ?: 'mixed';

        throw new ServiceResolveException(
            "Cannot resolve constructor parameter {$param} ({$typeStr}) for {$class}",
        );
    }
}