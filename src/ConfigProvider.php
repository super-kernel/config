<?php
declare (strict_types=1);

namespace SuperKernel\Config;

use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Contract\ConfigProviderInterface;
use SuperKernel\Contract\ProviderConfigInterface;

/**
 * @ConfigProvider
 * @\SuperKernel\Config\ConfigProvider
 */
final class ConfigProvider implements ConfigProviderInterface
{

	public function __construct()
	{
	}

	public function __invoke(): array
	{
		return [
			'dependencies' => [
				'factories' => [
					ConfigInterface::class         => ConfigFactory::class,
					ProviderConfigInterface::class => ProviderConfigFactory::class,
				],
			],
		];
	}
}