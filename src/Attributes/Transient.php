<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Attributes;

use Attribute;

/**
 * Mark a class as transient — a new instance is created on every `get()` call.
 *
 * Without this attribute the container caches instances (singleton behaviour).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Transient {}
