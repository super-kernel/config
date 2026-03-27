<?php
declare(strict_types=1);

namespace SuperKernel\Config\Provider;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;
use SuperKernel\Config\Attribute\Configuration;
use SuperKernel\Config\Config;
use SuperKernel\Config\Contract\ConfigInterface;
use SuperKernel\Config\Exception\DuplicateConfigurationException;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\AnnotationInterface;
use function get_class;

#[
	Provider(ConfigInterface::class),
	Factory,
]
final class ConfigProvider
{
	/**
	 * @param ContainerInterface $container
	 *
	 * @return ConfigInterface
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(ContainerInterface $container): ConfigInterface
	{
		$annotationCollector = $container->get(AnnotationCollectorInterface::class);

		$configs = [];
		/* @var AnnotationInterface $annotation */
		foreach ($annotationCollector->getClassesByAttribute(Configuration::class) as $annotation) {
			/* @var Configuration $configuration */
			$configuration = $annotation->getInstance();
			$interfaceName = $configuration->interface;
			$configurationClass = $annotation->getClass();

			if (isset($configs[$interfaceName])) {
				throw DuplicateConfigurationException::forInterface(
					$interfaceName,
					get_class($configs[$interfaceName]),
					$configurationClass,
				);
			}

			$configs[$configuration->interface] = $container->get($configurationClass);
		}

		return new Config($configs);
	}
}