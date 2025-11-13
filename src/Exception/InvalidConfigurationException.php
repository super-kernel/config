<?php
declare(strict_types=1);

namespace SuperKernel\Config\Exception;

use Psr\Container\ContainerExceptionInterface;
use SuperKernel\Di\Contract\DefinitionInterface;
use SuperKernel\Di\Exception\Exception;
use Throwable;

class InvalidConfigurationException extends Exception implements ContainerExceptionInterface
{
	public static function create(string $class): self
	{
		return new self(
			sprintf('Full configuration:' . PHP_EOL . '%s', $class));
	}
}