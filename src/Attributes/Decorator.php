<?php
declare(strict_types=1);

namespace MonkeysLegion\DI\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — DI Package
 *
 * Declare a service as a decorator for another service.
 *
 * The container will resolve the original service and inject it as the
 * constructor parameter named `$inner`. The decorator replaces the original
 * in the container.
 *
 * Usage:
 *   #[Decorator(decorates: LoggerInterface::class)]
 *   class CachingLogger implements LoggerInterface {
 *       public function __construct(
 *           private readonly LoggerInterface $inner,
 *           private readonly CacheInterface $cache,
 *       ) {}
 *   }
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Decorator
{
    public function __construct(
        public readonly string $decorates,
        public readonly int $priority = 0,
    ) {}
}
