<?php
declare(strict_types=1);

namespace SuperKernel\Config;

use SuperKernel\Contract\ConfigInterface;

/**
 * @Config
 * @\SuperKernel\Config\Config
 */
final class Config implements ConfigInterface
{
	private static array $configs = [];

	public function get(?string $key = null, mixed $default = null): mixed
	{
		if (null === $key) {
			return self::$configs;
		}

		return self::$configs[$key] ?? $default;
	}

	public function has(string $key): bool
	{
		return isset(self::$configs[$key]) || array_key_exists($key, self::$configs);
	}

	public function set(string $key, mixed $value): void
	{
		self::$configs[$key] = $value;
	}
}