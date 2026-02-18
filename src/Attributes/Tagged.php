<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Attributes;

use Attribute;

/**
 * Tag a service for aggregation.
 *
 * Services marked with the same tag can be retrieved as a group
 * via Container::getTagged('tag.name').
 *
 * Usage:
 *   #[Tagged('event.listener')]
 *   class MyListener { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Tagged
{
    public function __construct(
        public readonly string $tag,
    ) {}
}
