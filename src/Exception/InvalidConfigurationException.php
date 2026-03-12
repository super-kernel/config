<?php
declare(strict_types=1);

namespace SuperKernel\Config\Exception;

use RuntimeException;
use function sprintf;

class InvalidConfigurationException extends RuntimeException
{
	public static function create(string $class): self
	{
		return new self(
			sprintf('Full configuration:' . PHP_EOL . '%s', $class));
	}
}