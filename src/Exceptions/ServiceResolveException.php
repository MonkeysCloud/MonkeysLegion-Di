<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Exceptions;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Thrown when the container encounters an error while resolving a service.
 */
class ServiceResolveException extends RuntimeException implements ContainerExceptionInterface {}
