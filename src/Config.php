<?php
declare(strict_types=1);

namespace SuperKernel\Config;

use SuperKernel\Config\Contract\ConfigInterface;

final readonly class Config implements ConfigInterface
{
	public function __construct(private array $configs)
	{
	}

	public function get(string $interface): object
	{
		return $this->configs[$interface];
	}

	public function has(string $interface): bool
	{
		return isset($this->configs[$interface]);
	}
}