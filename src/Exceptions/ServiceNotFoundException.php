<?php

declare(strict_types=1);

namespace MonkeysLegion\DI\Exceptions;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Thrown when a requested service is not found in the container.
 */
class ServiceNotFoundException extends RuntimeException implements NotFoundExceptionInterface {}
