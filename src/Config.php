<?php
declare(strict_types=1);

namespace SuperKernel\Config;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use SuperKernel\Attribute\Contract;
use SuperKernel\Attribute\Factory;
use SuperKernel\Config\Attribute\Configuration;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Contract\ReflectionManagerInterface;
use function get_class;

#[
    Contract(ConfigInterface::class),
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
     * @param ContainerInterface $container
     * @return ConfigInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container): ConfigInterface
    {
        $logger = $container->get(LoggerInterface::class);
        $reflectionManager = $container->get(ReflectionManagerInterface::class);
        $classes = $reflectionManager->getAttributes(Configuration::class);

        foreach ($classes as $class) {
            $interfaces = $reflectionManager->reflectClass($class)->getInterfaces();

            foreach ($interfaces as $interface) {

                if ($this->has($interface)) {
                    $config = get_class($this->get($interface));

                    $logger->warning(
                        sprintf(
                            'Interface %s is implemented repeatedly, and class %s will replace class %s to provide configuration.',
                            $interface,
                            $class,
                            $config,
                        )
                    );
                }

                $this->configs[$interface->name] = $container->get($class);
            }
        }

        return $this;
    }
}