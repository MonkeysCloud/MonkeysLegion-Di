<?php
declare(strict_types=1);

namespace MonkeysLegion\DI\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — DI Package
 *
 * Use a static factory method instead of the constructor for service creation.
 *
 * Usage:
 *   #[Factory(method: 'create')]
 *   class DatabaseConnection {
 *       public static function create(Config $config): self { ... }
 *   }
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Factory
{
    public function __construct(
        public readonly string $method = 'create',
    ) {}
}
