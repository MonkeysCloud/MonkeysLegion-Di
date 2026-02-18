<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Attributes;

use Attribute;

/**
 * Override auto-wired parameter resolution with an explicit service ID.
 *
 * Usage:
 *   public function __construct(
 *       #[Inject('custom.logger')] LoggerInterface $logger,
 *   ) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Inject
{
    public function __construct(
        public readonly string $id,
    ) {}
}
