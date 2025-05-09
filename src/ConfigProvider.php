<?php
declare(strict_types=1);

namespace SuperKernel\Config;

use SuperKernel\Contract\ComposerInterface;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Contract\ConfigProviderInterface;

/**
 * @ConfigProvider
 * @\SuperKernel\Config\ConfigProvider
 */
final class ConfigProvider
{
	public function __invoke(): array
	{
		return [
			'dependencies' => [
				ConfigProviderInterface::class => ConfigProviderFactory::class,
				ConfigInterface::class         => ConfigFactory::class,
			],
		];
	}
}