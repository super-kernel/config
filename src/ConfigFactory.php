<?php
declare(strict_types=1);

namespace SuperKernel\Config;

use SuperKernel\Contract\ConfigInterface;

/**
 * @ConfigFactory
 * @\SuperKernel\Config\ConfigFactory
 */
final class ConfigFactory
{
	public function __invoke(): ConfigInterface
	{
		return new Config([]);
	}
}