<?php
declare(strict_types=1);

namespace MonkeysLegion\DI\Exceptions;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * MonkeysLegion Framework — DI Package
 *
 * Thrown when a requested service is not found in the container.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
class ServiceNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
