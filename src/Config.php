<?php
declare(strict_types=1);

namespace SuperKernel\Config;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use SuperKernel\Annotation\Factory;
use SuperKernel\Annotation\Provider;
use SuperKernel\Config\Attribute\Configuration;
use SuperKernel\Config\Contract\ConfigInterface;
use SuperKernel\Config\Exception\InvalidConfigurationException;
use SuperKernel\Contract\AttributeCollectorInterface;
use SuperKernel\Contract\ReflectorInterface;
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
	 * @param ContainerInterface          $container
	 * @param AttributeCollectorInterface $attributeCollector
	 * @param ReflectorInterface          $reflector
	 *
	 * @return ConfigInterface
	 * @throws ContainerExceptionInterface
	 * @throws InvalidConfigurationException
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(
		ContainerInterface          $container,
		AttributeCollectorInterface $attributeCollector,
		ReflectorInterface          $reflector,
	): ConfigInterface
	{
		foreach ($attributeCollector->getClassesByAttribute(Configuration::class) as $attribute) {
			$class = $attribute->getClass();
			$interfaces = $reflector->reflectClass($class)->getInterfaceNames();

			if (count($interfaces) > 1) {
				throw InvalidConfigurationException::create($class);
			}

			$this->configs[$interfaces[0]] = $container->get($class);
		}

		return $this;
	}
}