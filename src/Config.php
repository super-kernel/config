<?php
declare(strict_types=1);

namespace SuperKernel\Config;

use SuperKernel\Contract\ConfigInterface;

/**
 * @Config
 * @\SuperKernel\Config\Config
 */
final readonly class Config implements ConfigInterface
{
	public function __construct(private array $configs)
	{
	}

	public function get(?string $key = null, mixed $default = null): mixed
	{
		if (null === $key) {
			return $this->configs;
		}

		return $this->configs[$key] ?? $default;
	}

	public function has(string $key): bool
	{
		return isset($this->configs[$key]) || array_key_exists($key, $this->configs);
	}
}