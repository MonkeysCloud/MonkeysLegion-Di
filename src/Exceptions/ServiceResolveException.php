<?php
declare(strict_types=1);

namespace MonkeysLegion\DI\Exceptions;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * MonkeysLegion Framework — DI Package
 *
 * Thrown when the container encounters an error while resolving a service.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
class ServiceResolveException extends RuntimeException implements ContainerExceptionInterface
{
}
