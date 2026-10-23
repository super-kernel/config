<?php

declare(strict_types=1);

namespace SuperKernel\Config\Resolver;

use Psr\Container\ContainerExceptionInterface;
use SuperKernel\Attribute\Resolver;
use SuperKernel\Config\Definition\ProvisionDefinition;
use SuperKernel\Config\Exception\ConfigurationLoadingException;
use SuperKernel\Contract\ContainerInterface;
use SuperKernel\Di\Contract\DefinitionInterface;
use SuperKernel\Di\Contract\ResolverFactoryInterface;
use SuperKernel\Di\Contract\ResolverInterface;
use SuperKernel\Di\Definition\MethodDefinition;
use SuperKernel\Di\Exception\ResolutionException;
use Throwable;

use function array_is_list;
use function get_debug_type;
use function is_array;
use function sprintf;

#[Resolver(priority: 400)]
final readonly class ProvisionResolver implements ResolverInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function support(DefinitionInterface $definition): bool
    {
        return $definition instanceof ProvisionDefinition;
    }

    /** @throws ContainerExceptionInterface When source resolution, parameter injection, or invocation fails */
    public function resolve(DefinitionInterface $definition): mixed
    {
        if (!$definition instanceof ProvisionDefinition) {
            throw ResolutionException::unsupportedDefinition($definition);
        }

        try {
            $factory = $this->container->get($definition->factoryClass);
            $resolver = $this->container->get(ResolverFactoryInterface::class);
            $arguments = $resolver->resolve(new MethodDefinition($definition->factoryClass, $definition->methodName));

            if (!is_array($arguments) || !array_is_list($arguments)) {
                throw ResolutionException::invalidMethodArguments(
                    $definition->factoryClass,
                    $definition->methodName,
                    get_debug_type($arguments),
                );
            }

            return $factory->{$definition->methodName}(...$arguments);
        } catch (ContainerExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new ConfigurationLoadingException(sprintf(
                'Unable to invoke provision %s::%s for container entry "%s".',
                $definition->factoryClass,
                $definition->methodName,
                $definition->id,
            ), 0, $throwable);
        }
    }
}
