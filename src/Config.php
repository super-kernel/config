<?php
declare(strict_types=1);

namespace SuperKernel\Config;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;
use SuperKernel\Config\Attribute\Configuration;
use SuperKernel\Config\Exception\InvalidConfigurationException;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Di\Contract\AttributeCollectorInterface;
use SuperKernel\Di\Contract\ReflectionCollectorInterface;
use function count;

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
	 * @param ContainerInterface           $container
	 * @param AttributeCollectorInterface  $attributeCollector
	 * @param ReflectionCollectorInterface $reflectionCollector
	 *
	 * @return ConfigInterface
	 * @throws ContainerExceptionInterface
	 * @throws InvalidConfigurationException
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(
		ContainerInterface           $container,
		AttributeCollectorInterface  $attributeCollector,
		ReflectionCollectorInterface $reflectionCollector,
	): ConfigInterface
	{
		foreach ($attributeCollector->getAttributes(Configuration::class) as $attribute) {
			$class      = $attribute->class;
			$interfaces = $reflectionCollector->reflectClass($class)->getInterfaceNames();

			if (count($interfaces) > 1) {
				throw InvalidConfigurationException::create($class);
			}

			$this->configs[$interfaces[0]] = $container->get($class);
		}

		return $this;
	}
}