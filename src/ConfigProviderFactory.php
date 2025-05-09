<?php
declare (strict_types=1);

namespace SuperKernel\Config;

use SuperKernel\Contract\ConfigProviderInterface;

final class ConfigProviderFactory implements ConfigProviderInterface
{
	public function __invoke(): array
	{
		return [];
	}
}