<?php
declare(strict_types=1);

namespace MonkeysLegion\DI\Exceptions;

/**
 * MonkeysLegion Framework — DI Package
 *
 * Thrown when a circular dependency is detected during service resolution.
 * Includes the full dependency chain for debugging.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
class CircularDependencyException extends ServiceResolveException
{
    /** @var list<string> */
    private array $chain;

    /**
     * @param list<string> $chain The dependency chain that forms the cycle.
     */
    public function __construct(array $chain, ?\Throwable $previous = null)
    {
        $this->chain = $chain;
        $path = implode(' → ', $chain);

        parent::__construct(
            "Circular dependency detected: {$path}",
            0,
            $previous,
        );
    }

    /**
     * Get the dependency chain that forms the cycle.
     *
     * @return list<string>
     */
    public function getChain(): array
    {
        return $this->chain;
    }
}
