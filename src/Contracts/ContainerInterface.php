<?php
declare(strict_types=1);

namespace MonkeysLegion\DI\Contracts;

use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * MonkeysLegion Framework — DI Package
 *
 * Extended container contract with ML-specific capabilities.
 * Extends PSR-11 ContainerInterface adding auto-wiring, binding,
 * contextual resolution, method injection, tagging, and aliasing.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Register a service definition at runtime.
     *
     * @param string          $id         Service identifier (class name or alias).
     * @param callable|object $definition Factory closure or pre-built instance.
     */
    public function set(string $id, callable|object $definition): void;

    /**
     * Bind an abstract (interface) to a concrete implementation class.
     */
    public function bind(string $abstract, string $concrete): void;

    /**
     * Register a contextual binding.
     *
     * "When $consumer needs $abstract, give it $concrete."
     *
     * @param string          $consumer FQCN of the consuming class.
     * @param string          $abstract Interface or class being requested.
     * @param string|callable $concrete Concrete class name or factory closure.
     */
    public function contextual(string $consumer, string $abstract, string|callable $concrete): void;

    /**
     * Always create a new instance, ignoring singleton cache.
     *
     * @param string              $class  FQCN to resolve.
     * @param array<string,mixed> $params Constructor parameter overrides.
     */
    public function make(string $class, array $params = []): object;

    /**
     * Invoke a callable with auto-resolved parameters (method injection).
     *
     * @param callable|array<object|string,string> $callable Method, closure, or [class, method].
     * @param array<string,mixed>                  $params   Parameter overrides.
     */
    public function call(callable|array $callable, array $params = []): mixed;

    /**
     * Register an alias for an existing service ID.
     */
    public function alias(string $alias, string $id): void;

    /**
     * Tag a service ID with one or more tags for aggregation.
     *
     * @param string          $id   Service identifier.
     * @param string|string[] $tags One or more tag names.
     */
    public function tag(string $id, string|array $tags): void;

    /**
     * Retrieve all services marked with the given tag.
     *
     * @return list<mixed>
     */
    public function getTagged(string $tag): array;

    /**
     * Mark a service ID as transient (new instance on every get() call).
     */
    public function transient(string $id): void;

    /**
     * Extend / decorate an existing service.
     *
     * The callable receives the resolved service and the container, and must
     * return the (potentially wrapped) service.
     *
     * If the service is already cached as a singleton the extender is applied
     * immediately and the cache is updated.  Otherwise it is composed around
     * the existing factory and invoked on first resolution.
     *
     * @param string   $id       Service identifier.
     * @param callable $extender fn(mixed $service, static $container): mixed
     */
    public function extend(string $id, callable $extender): void;

    /**
     * Clear all resolved instances (useful for testing).
     */
    public function reset(): void;
}
