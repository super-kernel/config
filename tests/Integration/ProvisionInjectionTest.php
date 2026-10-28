<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Stringable;
use SuperKernel;
use SuperKernel\Attribute\Provision;
use SuperKernel\Config\Exception\ConfigurationLoadingException;
use SuperKernel\Config\Tests\Fixture\ConfigurationValue;
use SuperKernel\Config\Tests\Fixture\ProvisionFactory;
use SuperKernel\Config\Tests\Fixture\ProvisionProduct;
use SuperKernel\Config\Tests\Support\ProvisionContainerFactory;
use SuperKernel\Di\Container;
use SuperKernel\Di\Contract\DefinitionFactoryInterface;
use SuperKernel\Di\Contract\ResolverFactoryInterface;
use SuperKernel\Di\Exception\ResolutionException;
use Throwable;

final class ProvisionInjectionTest extends TestCase
{
    protected function setUp(): void
    {
        ProvisionFactory::$constructions = 0;
    }

    /** @throws Throwable When a provision or one of its dependencies cannot be resolved */
    public function testDiscoversHandlersAndInjectsFactoryAndMethodDependenciesLazily(): void
    {
        $container = $this->container();
        self::assertTrue($container->has(ProvisionProduct::class));
        self::assertSame(0, ProvisionFactory::$constructions);
        $product = $container->get(ProvisionProduct::class);
        self::assertInstanceOf(ProvisionProduct::class, $product);
        self::assertSame($container->get(ConfigurationValue::class), $product->constructorDependency);
        self::assertSame($product->constructorDependency, $product->methodDependency);
        self::assertSame($product, $container->get(ProvisionProduct::class));
        self::assertSame(1, ProvisionFactory::$constructions);
        $factory = $container->get(ProvisionFactory::class);
        self::assertInstanceOf(ProvisionFactory::class, $factory);
        self::assertSame([
            'product',
        ], $factory->calls);
    }

    /** @throws Throwable When named provisions cannot be resolved */
    public function testRetainsProductsPerIdentifierAndSharesTheirFactory(): void
    {
        $container = $this->container();
        $primary = $container->get('primary');
        $secondary = $container->get('secondary');
        self::assertInstanceOf(ProvisionProduct::class, $primary);
        self::assertInstanceOf(ProvisionProduct::class, $secondary);
        self::assertSame($primary, $container->get('primary'));
        self::assertSame($secondary, $container->get('secondary'));
        self::assertNotSame($primary, $secondary);
        self::assertSame('named', $primary->label);
        self::assertSame(1, ProvisionFactory::$constructions);
        $factory = $container->get(ProvisionFactory::class);
        self::assertInstanceOf(ProvisionFactory::class, $factory);
        self::assertSame([
            'named',
            'named',
        ], $factory->calls);
    }

    /** @throws Throwable When a failed provision cannot be retried */
    public function testInvocationFailuresKeepTheirCauseAndDoNotRetainFailedProducts(): void
    {
        $container = $this->container();
        $factory = $container->get(ProvisionFactory::class);
        self::assertInstanceOf(ProvisionFactory::class, $factory);
        $failure = new RuntimeException('temporary failure');
        $factory->failure = $failure;

        try {
            $container->get('unstable');
            self::fail('The configured provision failure must escape.');
        } catch (ConfigurationLoadingException $exception) {
            self::assertSame($failure, $exception->getPrevious());
            self::assertStringContainsString('::unstable', $exception->getMessage());
        }

        $factory->failure = null;
        $product = $container->get('unstable');
        self::assertInstanceOf(ProvisionProduct::class, $product);
        self::assertSame($product, $container->get('unstable'));
        self::assertSame(1, ProvisionFactory::$constructions);
        self::assertSame([
            'unstable',
            'unstable',
        ], $factory->calls);
    }

    /** @throws Throwable When factory setup or provision resolution fails */
    public function testPreservesPsrContainerFailures(): void
    {
        $container = $this->container();
        $factory = $container->get(ProvisionFactory::class);
        self::assertInstanceOf(ProvisionFactory::class, $factory);
        $failure = new ResolutionException('dependency failure');
        $factory->failure = $failure;

        try {
            $container->get('unstable');
            self::fail('The original container exception must escape.');
        } catch (ResolutionException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    /** @throws Throwable When a nullable named provision cannot be resolved */
    public function testRetainsNullForAnExplicitNonClassIdentifier(): void
    {
        $container = $this->container();
        self::assertTrue($container->has('nullable'));
        self::assertNull($container->get('nullable'));
        $factory = $container->get(ProvisionFactory::class);
        self::assertInstanceOf(ProvisionFactory::class, $factory);
        $dependency = new ConfigurationValue();
        $factory->nullableResult = new ProvisionProduct($dependency, $dependency);
        self::assertNull($container->get('nullable'));
        self::assertSame([
            'nullable',
        ], $factory->calls);
    }

    /** @throws Throwable When direct provision resolution fails */
    public function testDirectResolutionDoesNotRetainTheTopLevelProduct(): void
    {
        $container = $this->container();
        $definitions = $container->get(DefinitionFactoryInterface::class);
        $resolver = $container->get(ResolverFactoryInterface::class);
        self::assertInstanceOf(DefinitionFactoryInterface::class, $definitions);
        self::assertInstanceOf(ResolverFactoryInterface::class, $resolver);
        $definition = $definitions->get(ProvisionProduct::class);
        self::assertNotNull($definition);
        $first = $resolver->resolve($definition);
        $second = $resolver->resolve($definition);
        self::assertInstanceOf(ProvisionProduct::class, $first);
        self::assertInstanceOf(ProvisionProduct::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame($first->constructorDependency, $second->constructorDependency);
        $retained = $container->get(ProvisionProduct::class);
        self::assertNotSame($first, $retained);
        self::assertNotSame($second, $retained);
        self::assertSame($retained, $container->get(ProvisionProduct::class));
        self::assertSame(1, ProvisionFactory::$constructions);
    }

    /** @throws Throwable When the higher-priority factory provider cannot be resolved */
    public function testExistingFactoryProvidersTakePriorityOverProvisionMethods(): void
    {
        $container = ProvisionContainerFactory::create([
            SuperKernel\Config\Tests\Fixture\ProvisionFactory::class,
            SuperKernel\Config\Tests\Fixture\Provider\ProvisionProductProvider::class,
        ]);
        $product = $container->get(ProvisionProduct::class);
        self::assertInstanceOf(ProvisionProduct::class, $product);
        self::assertSame('override', $product->label);
        self::assertSame(0, ProvisionFactory::$constructions);
    }

    /** @throws Throwable When the invalid class-ID result is resolved */
    public function testContainerValidatesExplicitClassIdentifiers(): void
    {
        $factory = new class() {
            #[Provision(Stringable::class)]
            public function incompatible(): stdClass
            {
                return new stdClass();
            }
        };
        $container = ProvisionContainerFactory::create([
            $factory::class,
        ]);
        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessageIsOrContains('instead of an instance of Stringable');

        $container->get(Stringable::class);
    }

    /** @throws Throwable When provision fixtures cannot be collected */
    private function container(): Container
    {
        return ProvisionContainerFactory::create([
            SuperKernel\Config\Tests\Fixture\ProvisionFactory::class,
        ]);
    }
}
