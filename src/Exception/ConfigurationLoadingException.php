<?php

declare(strict_types=1);

namespace SuperKernel\Config\Exception;

use RuntimeException;

final class ConfigurationLoadingException extends RuntimeException implements ConfigExceptionInterface
{
}
