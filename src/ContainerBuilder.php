<?php
declare(strict_types=1);

namespace MonkeysLegion\DI;

use MonkeysLegion\DI\Contracts\ServiceProviderInterface;

/**
 * MonkeysLegion Framework — DI Package
 *
 * Fluent builder for constructing a Container (or CompiledContainer).
 *
 * Providers register definitions via addDefinitions() or set(), then call
 * build() to obtain the ready-to-use container.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ContainerBuilder
{
    /** @var array<string, callable|object> */
    private array $definitions = [];

    /** @var array<string, string> Interface → concrete. */
    private array $bindings = [];

    /** @var array<string, string|string[]> ID → tag(s). */
    private array $tags = [];

    /** @var list<string> IDs marked as transient. */
    private array $transients = [];

    /** @var array<string, string> Alias → target. */
    private array $aliases = [];

    /** @var array<string, array<string, string|callable>> Consumer → [abstract → concrete]. */
    private array $contextualBindings = [];

    /** @var array<string, list<callable>> Service extensions to apply after build. */
    private array $extensions = [];

    private ?string $compilationDir = null;

    // ── Definitions ────────────────────────────────────────────────

    /**
     * Merge an array of definitions.
     *
     * @param array<string, callable|object> $defs
     */
    public function addDefinitions(array $defs): self
    {
        $this->definitions += $defs;
        return $this;
    }

    /**
     * Register a single definition.
     */
    public function set(string $id, callable|object $definition): self
    {
        $this->definitions[$id] = $definition;
        return $this;
    }

    // ── Bindings ───────────────────────────────────────────────────

    /**
     * Bind an interface to a concrete class.
     */
    public function bind(string $abstract, string $concrete): self
    {
        $this->bindings[$abstract] = $concrete;
        return $this;
    }

    /**
     * Register a contextual binding.
     *
     * @param string          $consumer FQCN of the consuming class.
     * @param string          $abstract Interface or class being requested.
     * @param string|callable $concrete Concrete class name or factory closure.
     */
    public function contextual(string $consumer, string $abstract, string|callable $concrete): self
    {
        $this->contextualBindings[$consumer][$abstract] = $concrete;
        return $this;
    }

    // ── Tagging ────────────────────────────────────────────────────

    /**
     * Tag a service.
     *
     * @param string|string[] $tags
     */
    public function tag(string $id, string|array $tags): self
    {
        $this->tags[$id] = $tags;
        return $this;
    }

    // ── Transients ─────────────────────────────────────────────────

    /**
     * Mark a service as transient (new instance on every get()).
     */
    public function transient(string $id): self
    {
        $this->transients[] = $id;
        return $this;
    }

    // ── Aliases ────────────────────────────────────────────────────

    /**
     * Register an alias.
     */
    public function alias(string $alias, string $id): self
    {
        $this->aliases[$alias] = $id;
        return $this;
    }

    // ── Extensions ─────────────────────────────────────────────────

    /**
     * Extend / decorate a service after it is resolved.
     *
     * Extensions are applied to the built container via Container::extend().
     * Multiple extensions for the same ID are applied in registration order.
     *
     * @param string   $id       Service identifier.
     * @param callable $extender fn(mixed $service, Container $container): mixed
     */
    public function extend(string $id, callable $extender): self
    {
        $this->extensions[$id][] = $extender;
        return $this;
    }

    // ── Service Providers ──────────────────────────────────────────

    /**
     * Register a service provider.
     *
     * The provider's register() method is called immediately so that it can
     * add its own definitions, bindings, tags, etc. via the fluent builder API.
     */
    public function addServiceProvider(ServiceProviderInterface $provider): self
    {
        $provider->register($this);
        return $this;
    }

    // ── Compilation ────────────────────────────────────────────────

    /**
     * Enable compiled container mode.
     *
     * @param string $cacheDir Directory to store the compiled definitions file.
     */
    public function enableCompilation(string $cacheDir): self
    {
        $this->compilationDir = rtrim($cacheDir, '/');
        return $this;
    }

    // ── Build ──────────────────────────────────────────────────────

    /**
     * Build and return the container.
     *
     * If compilation is enabled and a cached file exists, a CompiledContainer
     * is returned instead.
     */
    public function build(): Container
    {
        $compiledFile = $this->compilationDir
            ? $this->compilationDir . '/compiled_container.php'
            : null;

        if ($compiledFile !== null && file_exists($compiledFile)) {
            $container = new CompiledContainer($compiledFile, $this->definitions);
        } else {
            $container = new Container($this->definitions);
        }

        // Apply bindings
        foreach ($this->bindings as $abstract => $concrete) {
            $container->bind($abstract, $concrete);
        }

        // Apply contextual bindings
        foreach ($this->contextualBindings as $consumer => $bindings) {
            foreach ($bindings as $abstract => $concrete) {
                $container->contextual($consumer, $abstract, $concrete);
            }
        }

        // Apply tags
        foreach ($this->tags as $id => $tags) {
            $container->tag($id, $tags);
        }

        // Apply transients
        foreach ($this->transients as $id) {
            $container->transient($id);
        }

        // Apply aliases
        foreach ($this->aliases as $alias => $id) {
            $container->alias($alias, $id);
        }

        // Apply extensions
        foreach ($this->extensions as $id => $extenders) {
            foreach ($extenders as $extender) {
                $container->extend($id, $extender);
            }
        }

        // Set the global instance
        Container::setInstance($container);

        return $container;
    }
}