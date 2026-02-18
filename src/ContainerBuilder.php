<?php

declare(strict_types=1);

namespace MonkeysLegion\DI;

/**
 * Fluent builder for constructing a Container (or CompiledContainer).
 *
 * Providers register definitions via addDefinitions() or set(), then call
 * build() to obtain the ready-to-use container.
 */
final class ContainerBuilder
{
    /** @var array<string, callable|object> */
    private array $definitions = [];

    /** @var array<string, string> interface → concrete */
    private array $bindings = [];

    /** @var array<string, string|string[]> id → tag(s) */
    private array $tags = [];

    /** @var list<string> IDs marked as transient */
    private array $transients = [];

    private ?string $compilationDir = null;

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

    /**
     * Bind an interface to a concrete class.
     */
    public function bind(string $abstract, string $concrete): self
    {
        $this->bindings[$abstract] = $concrete;
        return $this;
    }

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

    /**
     * Mark a service as transient (new instance on every get()).
     */
    public function transient(string $id): self
    {
        $this->transients[] = $id;
        return $this;
    }

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

        // Apply tags
        foreach ($this->tags as $id => $tags) {
            $container->tag($id, $tags);
        }

        // Apply transients
        foreach ($this->transients as $id) {
            $container->transient($id);
        }

        return $container;
    }
}