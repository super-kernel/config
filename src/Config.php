<?php
declare(strict_types=1);

namespace SuperKernel\Config;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use SuperKernel\Attribute\Configuration;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\AttributeCollectorInterface;
use SuperKernel\Contract\ConfigInterface;

#[
	Provider(ConfigInterface::class),
	Factory,
]
final class Config implements ConfigInterface
{
	private array $configs = [];

	public function get(string $interface): object
	{
		return $this->configs[$interface];
	}

	public function has(string $interface): bool
	{
		return isset($this->configs[$interface]);
	}

	/**
	 * @param ContainerInterface          $container
	 * @param AttributeCollectorInterface $attributeCollector
	 *
	 * @return ConfigInterface
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(
		ContainerInterface          $container,
		AttributeCollectorInterface $attributeCollector,
	): ConfigInterface
	{
		foreach ($attributeCollector->getAttributes(Configuration::class) as $class => $attributes) {
			/* @var Configuration $attribute */
			foreach ($attributes as $attribute) {
				$this->configs[$attribute->interface] = $container->get($class);
			}
		}

		return $this;
	}
}