<?php
declare(strict_types=1);

namespace MonkeysLegion\DI\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — DI Package
 *
 * Mark a parameter or class for lazy proxy injection.
 *
 * When applied, the container injects a lightweight proxy that defers
 * actual resolution until the first method call. Uses PHP 8.4 native
 * `ReflectionClass::newLazyProxy()` for zero-overhead lazy loading.
 *
 * Usage on parameter:
 *   public function __construct(
 *       #[Lazy] HeavyService $service,
 *   ) {}
 *
 * Usage on class (all injections of this class become lazy):
 *   #[Lazy]
 *   class HeavyService { ... }
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_CLASS)]
final class Lazy
{
}
