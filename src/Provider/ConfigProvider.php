<?php
declare(strict_types=1);

namespace SuperKernel\Config\Provider;

use SuperKernel\Config\Contract\ConfigInterface;

final class ConfigProvider
{
	public function __invoke(): ConfigInterface
	{
	}
}