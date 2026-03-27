<?php
declare(strict_types=1);

namespace SuperKernel\Config\Exception;

use RuntimeException;
use function sprintf;

final class DuplicateConfigurationException extends RuntimeException
{
	public static function forInterface(string $interface, string $existingClass, string $newClass): self
	{
		return new self(
			sprintf(
				'Duplicate configuration detected for interface [%s]. ' .
				'Already implemented by [%s], cannot be overridden by [%s].',
				$interface,
				$existingClass,
				$newClass,
			),
		);
	}
}