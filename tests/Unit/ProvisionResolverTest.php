<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Unit;

use PHPUnit\Framework\TestCase;
use stdClass;
use SuperKernel\Config\Definition\ProvisionDefinition;
use SuperKernel\Config\Resolver\ProvisionResolver;
use SuperKernel\Config\Tests\Fixture\ConfigurationValue;
use SuperKernel\Config\Tests\Fixture\ProvisionFactory;
use SuperKernel\Contract\ContainerInterface;
use SuperKernel\Di\Contract\ResolverFactoryInterface;
use SuperKernel\Di\Definition\ClassDefinition;
use SuperKernel\Di\Definition\MethodDefinition;
use SuperKernel\Di\Exception\ResolutionException;
use Throwable;

final class ProvisionResolverTest extends TestCase
{
    /** @throws Throwable When the unsupported definition is rejected */
    public function testRejectsOtherDefinitionTypesWithoutResolvingServices(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $resolver = new ProvisionResolver($container);
        $definition = new ClassDefinition(stdClass::class);
        self::assertFalse($resolver->support($definition));
        $this->expectException(ResolutionException::class);

        $resolver->resolve($definition);
    }

    /** @throws Throwable When a non-positional argument result is rejected */
    public function testRejectsInvalidMethodArgumentsBeforeInvokingTheFactory(): void
    {
        $factory = new ProvisionFactory(new ConfigurationValue());
        $dispatcher = $this->createMock(ResolverFactoryInterface::class);
        $dispatcher->expects(self::once())->method('resolve')->willReturnCallback(
            static function (MethodDefinition $definition): array {
                self::assertSame(ProvisionFactory::class, $definition->className);
                self::assertSame('named', $definition->methodName);

                return [
                    'unexpected' => null,
                ];
            },
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(2))->method('get')->willReturnCallback(
            static fn(string $id): object => ProvisionFactory::class === $id ? $factory : $dispatcher,
        );
        $resolver = new ProvisionResolver($container);
        $definition = new ProvisionDefinition('invalid', ProvisionFactory::class, 'named');
        self::assertTrue($resolver->support($definition));

        try {
            $resolver->resolve($definition);
            self::fail('Non-positional arguments must be rejected.');
        } catch (ResolutionException $exception) {
            self::assertStringContainsString('instead of an argument list', $exception->getMessage());
        }

        self::assertSame([], $factory->calls);
    }
}
