<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Attributes;

use Attribute;

/**
 * Mark a class as singleton (default behaviour).
 *
 * Resolved instances are cached and returned on subsequent `get()` calls.
 * This is the default lifecycle; use this attribute for explicit documentation.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Singleton {}
