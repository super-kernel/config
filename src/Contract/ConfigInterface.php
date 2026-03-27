<?php
declare(strict_types=1);

namespace SuperKernel\Config\Contract;

interface ConfigInterface
{
	public function get(string $interface): object;

	public function has(string $interface): bool;
}