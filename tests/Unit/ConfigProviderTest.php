<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Unit;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Stringable;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Attribute\Configuration;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Config\Exception\ConfigExceptionInterface;
use SuperKernel\Config\Exception\ConfigurationLoadingException;
use SuperKernel\Config\Exception\InvalidConfigurationException;
use SuperKernel\Config\Provider\ConfigProvider;
use SuperKernel\Config\Tests\Fixture\ConcreteConfigurationItems;
use SuperKernel\Config\Tests\Fixture\ConcreteOption;
use SuperKernel\Config\Tests\Fixture\ConfigurationValue;
use SuperKernel\Config\Tests\Fixture\MultipleApplicationConfig;
use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Contract\ContainerInterface as ContractContainerInterface;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\Di\Resolver\MethodResolver;
use SuperKernel\Reflector\Provider\ReflectorProvider;
use Throwable;
use Traversable;

use function array_key_exists;

final class ConfigProviderTest extends TestCase
{
    /** @throws Throwable When concrete options are discovered or their source is loaded */
    public function testDiscoversConcreteItemsAndRetainsThemWithoutResolvingItemsThroughDi(): void
    {
        $source = new ConcreteConfigurationItems();
        $resolved = [];
        $config = new ConfigProvider()(
            $this->collector([
                $source::class => $this->metadata(ConcreteOption::class),
            ]),
            $this->context([
                $source::class => $source,
            ], $resolved),
        );
        self::assertTrue($config->has(ConcreteOption::class, 'cache'));
        self::assertSame([], $resolved);
        self::assertSame([], $source->calls);
        self::assertNull($config->get(ConcreteOption::class, 'missing'));
        $primary = $config->get(ConcreteOption::class);
        self::assertInstanceOf(ConcreteOption::class, $primary);
        self::assertSame($primary, $config->get(ConcreteOption::class, 'primary'));
        self::assertSame('primary', $primary->value);
        $cache = $config->get(ConcreteOption::class, 'cache');
        self::assertInstanceOf(ConcreteOption::class, $cache);
        self::assertSame('cache', $cache->value);
        self::assertSame([
            $source::class,
        ], $resolved);
        self::assertSame([
            'primary' => 1,
            'cache'   => 1,
        ], $source->calls);
    }

    /** @throws Throwable When multi-source declaration or item loading fails */
    public function testRegistersExplicitMultipleSourcesWithoutInvokingTheirItems(): void
    {
        $source = new MultipleApplicationConfig();
        $resolved = [];
        $config = new ConfigProvider()(
            $this->collector([
                $source::class => $this->metadata(Stringable::class),
            ]),
            $this->context([
                $source::class => $source,
            ], $resolved),
        );
        self::assertSame([], $resolved);
        self::assertSame([], $source->calls);
        self::assertTrue($config->has(Stringable::class, 'cache'));
        self::assertSame('cache', (string) $config->get(Stringable::class, 'cache'));
        self::assertSame([
            'cache' => 1,
        ], $source->calls);
    }

    public function testMultipleSourcesRequireAnExplicitItemInterface(): void
    {
        $source = new MultipleApplicationConfig();
        $resolved = [];
        $this->expectException(InvalidConfigurationException::class);

        new ConfigProvider()(
            $this->collector([
                $source::class => $this->metadata(),
            ]),
            $this->context([], $resolved),
        );
    }

    /** @throws Throwable When a single configuration source cannot be resolved */
    public function testSingleModeMarkerDoesNotCompeteWithTheInferredItemInterface(): void
    {
        $source = new ConfigurationValue();
        $resolved = [];
        $config = new ConfigProvider()(
            $this->collector([
                $source::class => $this->metadata(),
            ]),
            $this->context([
                $source::class => $source,
            ], $resolved),
        );

        self::assertSame($source, $config->get(Stringable::class));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRegistersExplicitAndInferredSourcesWithoutLoadingThemEagerly(): void
    {
        $explicit = new class() implements Stringable {
            public function __toString(): string
            {
                return 'explicit';
            }
        };
        $inferred = new class() implements Countable {
            public function count(): int
            {
                return 1;
            }
        };
        $resolved = [];
        $config = new ConfigProvider()(
            $this->collector([
                $explicit::class => $this->metadata(Stringable::class),
                $inferred::class => $this->metadata(),
            ]),
            $this->context([
                $explicit::class => $explicit,
                $inferred::class => $inferred,
            ], $resolved),
        );

        self::assertSame([], $resolved);
        self::assertSame($explicit, $config->get(Stringable::class));
        self::assertSame([
            $explicit::class,
        ], $resolved);
        self::assertSame($inferred, $config->get(Countable::class));
        self::assertSame([
            $explicit::class,
            $inferred::class,
        ], $resolved);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testInferenceIgnoresAnInheritedParentInterface(): void
    {
        $configuration = new class() implements IteratorAggregate {
            public function getIterator(): Traversable
            {
                return new ArrayIterator([]);
            }
        };
        $resolved = [];
        $config = new ConfigProvider()(
            $this->collector([
                $configuration::class => $this->metadata(),
            ]),
            $this->context([
                $configuration::class => $configuration,
            ], $resolved),
        );

        self::assertSame($configuration, $config->get(IteratorAggregate::class));
    }

    public function testValidatesEveryDeclarationBeforeLoadingAnySource(): void
    {
        $valid = new class() implements Stringable {
            public function __toString(): string
            {
                return 'valid';
            }
        };
        $ambiguous = new class() implements Countable, Stringable {
            public function count(): int
            {
                return 1;
            }

            public function __toString(): string
            {
                return 'ambiguous';
            }
        };
        $resolved = [];

        try {
            new ConfigProvider()(
                $this->collector([
                    $valid::class     => $this->metadata(),
                    $ambiguous::class => $this->metadata(),
                ]),
                $this->context([
                    $valid::class     => $valid,
                    $ambiguous::class => $ambiguous,
                ], $resolved),
            );
            self::fail('Ambiguous inferred configuration must fail.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString('exactly one most-specific interface', $exception->getMessage());
        }

        self::assertSame([], $resolved);
    }

    public function testRejectsDuplicateContracts(): void
    {
        $first = new class() implements Stringable {
            public function __toString(): string
            {
                return 'first';
            }
        };
        $second = new class() implements Stringable {
            public function __toString(): string
            {
                return 'second';
            }
        };
        $resolved = [];

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('is declared by both');

        new ConfigProvider()(
            $this->collector([
                $first::class  => $this->metadata(),
                $second::class => $this->metadata(),
            ]),
            $this->context([], $resolved),
        );
    }

    public function testRejectsAnIncompatibleExplicitClassContract(): void
    {
        $configuration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'invalid';
            }
        };
        $resolved = [];

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('must be compatible with its declared contract');

        new ConfigProvider()(
            $this->collector([
                $configuration::class => $this->metadata(stdClass::class),
            ]),
            $this->context([], $resolved),
        );
    }

    public function testRejectsAnExplicitInterfaceThatTheSourceDoesNotImplement(): void
    {
        $configuration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'invalid';
            }
        };
        $resolved = [];

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('must be compatible with its declared contract');

        new ConfigProvider()(
            $this->collector([
                $configuration::class => $this->metadata(Countable::class),
            ]),
            $this->context([], $resolved),
        );
    }

    /**
     * These assertions protect the public interface contract even when static types already imply it.
     *
     * @throws Throwable When fixture setup or the operation under test throws
     *
     * @noinspection PhpConditionAlreadyCheckedInspection
     */
    public function testWrapsSourceLoadingFailuresAsConfigExceptions(): void
    {
        $configuration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'unavailable';
            }
        };
        $failure = new RuntimeException('container failed');
        $resolved = [];

        try {
            $config = new ConfigProvider()(
                $this->collector([
                    $configuration::class => $this->metadata(),
                ]),
                $this->context([], $resolved, $failure),
            );
            $config->get(Stringable::class);
            self::fail('Source loading failure must be wrapped.');
        } catch (ConfigurationLoadingException $exception) {
            self::assertInstanceOf(ConfigExceptionInterface::class, $exception);
            self::assertSame($failure, $exception->getPrevious());
            self::assertStringContainsString($configuration::class, $exception->getMessage());
            self::assertStringContainsString(Stringable::class, $exception->getMessage());
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsAnIncompatibleResolvedSource(): void
    {
        $configuration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'expected';
            }
        };
        $resolved = [];

        $this->expectException(ConfigurationLoadingException::class);
        $this->expectExceptionMessageIsOrContains('resolved to stdClass');

        $config = new ConfigProvider()(
            $this->collector([
                $configuration::class => $this->metadata(),
            ]),
            $this->context([
                $configuration::class => new stdClass(),
            ], $resolved),
        );
        $config->get(Stringable::class);
    }

    public function testDeclaresTheConfigInterfaceFactoryMapping(): void
    {
        $reflection = new ReflectionClass(ConfigProvider::class);
        $attributes = $reflection->getAttributes(FactoryProvider::class);
        $parameters = $reflection->getMethod('__invoke')->getParameters();

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertCount(1, $attributes);
        self::assertSame(ConfigInterface::class, $attributes[0]->newInstance()->className);
        self::assertCount(2, $parameters);
        self::assertSame(AnnotationCollectorInterface::class, (string) $parameters[0]->getType());
        self::assertSame(ReflectorInterface::class, (string) $parameters[1]->getType());
    }

    /**
     * @param array<class-string, non-empty-list<AttributeMetadata<Configuration>>> $declarations
     */
    private function collector(array $declarations): AnnotationCollectorInterface
    {
        $collector = $this->createMock(AnnotationCollectorInterface::class);
        $collector->expects(self::once())
            ->method('getClassesWithAttribute')
            ->with(Configuration::class)
            ->willReturn($declarations);

        return $collector;
    }

    /**
     * @param array<class-string, object> $entries
     * @param list<class-string>          $resolved
     */
    private function context(
        array      $entries,
        array     &$resolved,
        ?Throwable $failure = null,
    ): ReflectorInterface
    {
        $reflector = new ReflectorProvider()();
        $container = $this->createStub(ContractContainerInterface::class);
        $resolver = new MethodResolver($container);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => ContainerInterface::class === $id
                || ReflectorInterface::class === $id,
        );
        $container->method('get')->willReturnCallback(
            static function (string $id) use (
                $entries,
                &$resolved,
                $failure,
                $container,
                $reflector,
                $resolver,
            ): object {
                if (MethodResolver::class === $id) {
                    return $resolver;
                }

                if (ContainerInterface::class === $id) {
                    return $container;
                }

                if (ReflectorInterface::class === $id) {
                    return $reflector;
                }

                $resolved[] = $id;

                if ($failure instanceof Throwable) {
                    throw $failure;
                }

                if (!array_key_exists($id, $entries)) {
                    throw new RuntimeException('Missing test container entry ' . $id);
                }

                return $entries[$id];
            },
        );

        ApplicationContext::setContainer($container);

        return $reflector;
    }

    /** @return non-empty-list<AttributeMetadata<Configuration>> */
    private function metadata(?string $className = null): array
    {
        return [
            new AttributeMetadata(new Configuration($className)),
        ];
    }
}
