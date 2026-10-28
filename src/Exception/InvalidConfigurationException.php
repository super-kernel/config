<?php

declare(strict_types=1);

namespace SuperKernel\Config\Exception;

use RuntimeException;

final class InvalidConfigurationException extends RuntimeException implements ConfigExceptionInterface
{
}
