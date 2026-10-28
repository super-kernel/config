<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Stringable;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Config\Exception\ConfigurationNotFoundException;
use SuperKernel\Config\Provider\ConfigProvider;
use SuperKernel\Config\Tests\Fixture\ApplicationConfig;
use SuperKernel\Config\Tests\Fixture\ConfigConsumer;
use SuperKernel\Config\Tests\Fixture\MultipleApplicationConfig;
use SuperKernel\Config\Tests\Fixture\Provider\ValidatedConfigProvider;
use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Di\Container;
use SuperKernel\Di\Definer\ClassDefiner;
use SuperKernel\Di\Definer\FactoryDefiner;
use SuperKernel\Di\Definer\ProviderDefiner;
use SuperKernel\Di\Exception\ResolutionException;
use SuperKernel\Di\PropertyHandler\AutowiredPropertyHandler;
use SuperKernel\Di\Resolver\ClassResolver;
use SuperKernel\Di\Resolver\FactoryResolver;
use SuperKernel\Di\Resolver\MethodResolver;
use SuperKernel\Di\Resolver\PropertyResolver;
use SuperKernel\Di\Resolver\ProviderResolver;
use SuperKernel\Reflector\Provider\ReflectorProvider;
use Throwable;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ConfigurationInjectionTest extends TestCase
{
    /** @throws Throwable When the multi-source or its validated default cannot be injected */
    public function testInjectsTheValidatedDefaultItemFromAMultipleSource(): void
    {
        $container = $this->container(source: MultipleApplicationConfig::class);
        $consumer = $container->get(ConfigConsumer::class);
        self::assertInstanceOf(ConfigConsumer::class, $consumer);
        $registry = $container->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $registry);

        self::assertSame($consumer->config, $consumer->injected);
        self::assertSame($consumer->config, $registry->get(Stringable::class, 'primary'));
        self::assertSame('primary', (string) $consumer->config);
        self::assertSame('cache', (string) $registry->get(Stringable::class, 'cache'));
    }

    /** @throws Throwable when the configuration or DI graph cannot be resolved */
    public function testInjectsTheValidatedSourceThroughBothConstructorAndProperty(): void
    {
        $container = $this->container();
        $consumer = $container->get(ConfigConsumer::class);
        self::assertInstanceOf(ConfigConsumer::class, $consumer);
        $source = $container->get(ApplicationConfig::class);
        self::assertInstanceOf(ApplicationConfig::class, $source);

        self::assertSame($source, $consumer->config);
        self::assertSame($source, $consumer->injected);
        self::assertSame($source, $container->get(Stringable::class));
        $registry = $container->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $registry);
        self::assertSame($source, $registry->get(Stringable::class));
        self::assertSame(1, $source->reads);
    }

    /** @throws Throwable when the configuration or DI graph cannot be resolved */
    public function testFailedValidationDoesNotPublishAnInterfaceEntry(): void
    {
        $container = $this->container();
        $source = $container->get(ApplicationConfig::class);
        self::assertInstanceOf(ApplicationConfig::class, $source);
        $source->value = '';

        for ($attempt = 1; 2 >= $attempt; ++$attempt) {
            try {
                $container->get(Stringable::class);
                self::fail('Invalid configuration must not enter the container.');
            } catch (ResolutionException $exception) {
                self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
            }
            self::assertSame($attempt, $source->reads);
        }

        $source->value = 'corrected';
        self::assertSame($source, $container->get(Stringable::class));
        self::assertSame($source, $container->get(Stringable::class));
        self::assertSame(3, $source->reads);
    }

    /** @throws Throwable when the configuration or DI graph cannot be resolved */
    public function testInterfaceFactoryDoesNotHideAMissingConfigurationDeclaration(): void
    {
        $container = $this->container(false);
        self::assertTrue($container->has(Stringable::class));
        $registry = $container->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $registry);
        self::assertFalse($registry->has(Stringable::class));
        self::assertNull($registry->get(Stringable::class));
        $this->expectException(ConfigurationNotFoundException::class);

        $container->get(Stringable::class);
    }

    /**
     * @param class-string|null $source
     *
     * @throws ReflectionException When a fixture or production declaration cannot be reflected
     */
    private function container(bool $declareSource = true, ?string $source = null): Container
    {
        $classes = [
            ProviderDefiner::class,
            FactoryDefiner::class,
            ClassDefiner::class,
            FactoryResolver::class,
            ProviderResolver::class,
            ClassResolver::class,
            PropertyResolver::class,
            MethodResolver::class,
            AutowiredPropertyHandler::class,
            ReflectorProvider::class,
            ConfigProvider::class,
            ValidatedConfigProvider::class,
        ];
        if ($declareSource) {
            $classes[] = $source ?? ApplicationConfig::class;
        }
        $metadata = [];
        foreach ($classes as $class) {
            foreach (new ReflectionClass($class)->getAttributes() as $attribute) {
                $metadata[$class][] = new AttributeMetadata($attribute->newInstance());
            }
        }
        $container = new Container(new AnnotationCollector([
            new PackageAnnotations('super-kernel/config-test', null, $metadata, [], []),
        ]));
        ApplicationContext::setContainer($container);

        return $container;
    }
}
