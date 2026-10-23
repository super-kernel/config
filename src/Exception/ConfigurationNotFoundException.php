<?php

declare(strict_types=1);

namespace SuperKernel\Config\Exception;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ConfigurationNotFoundException extends RuntimeException implements ConfigExceptionInterface, NotFoundExceptionInterface
{
}
