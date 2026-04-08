<?php
declare(strict_types=1);

namespace MonkeysLegion\DI\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — DI Package
 *
 * Register alternative service IDs (aliases) for a class.
 *
 * Usage:
 *   #[Alias('logger')]
 *   #[Alias('app.logger')]
 *   class FileLogger implements LoggerInterface { ... }
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Alias
{
    public function __construct(
        public readonly string $name,
    ) {}
}
