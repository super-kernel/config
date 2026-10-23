<?php

declare(strict_types=1);

namespace SuperKernel\Config\Exception;

use RuntimeException;

final class CircularConfigurationDependencyException extends RuntimeException implements ConfigExceptionInterface
{
}
