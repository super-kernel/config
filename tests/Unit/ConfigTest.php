<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Unit;

use ArrayObject;
use Countable;
use Fiber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Stringable;
use SuperKernel\Config\Config;
use SuperKernel\Config\Exception\CircularConfigurationDependencyException;
use SuperKernel\Config\Exception\ConfigExceptionInterface;
use SuperKernel\Config\Exception\ConfigurationLoadingException;
use SuperKernel\Config\Exception\InvalidConfigurationException;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Reflector\Provider\ReflectorProvider;
use Throwable;

use function get_debug_type;

final class ConfigTest extends TestCase
{
    /** @throws ConfigExceptionInterface on an unexpected source-resolution attempt */
    public function testChecksExactDeclarationsWithoutResolvingSources(): void
    {
        $configuration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'declared';
            }
        };
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('has');
        $container->expects(self::never())->method('get');
        $config = $this->config([
            Stringable::class => $configuration::class,
        ], $container);

        self::assertTrue($config->has(Stringable::class));
        self::assertFalse($config->has(Countable::class));
        self::assertFalse($config->has($configuration::class));
        self::assertNull($config->get(Countable::class));
        self::assertNull($config->get($configuration::class));
        self::assertTrue($config->has(Stringable::class));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testLazilyResolvesAndRetainsATypedConfiguration(): void
    {
        $configuration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'configured';
            }
        };
        $resolutions = 0;
        $container = $this->container(static function (string $id) use ($configuration, &$resolutions): object {
            ++$resolutions;
            self::assertSame($configuration::class, $id);

            return $configuration;
        });
        $config = $this->config([
            Stringable::class => $configuration::class,
        ], $container);
        $reflection = new ReflectionClass($config);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->implementsInterface(ConfigInterface::class));
        self::assertTrue($config->has(Stringable::class));
        self::assertSame(0, $resolutions);
        self::assertSame($configuration, $config->get(Stringable::class));
        self::assertSame($configuration, $config->get(Stringable::class));
        self::assertTrue($config->has(Stringable::class));
        self::assertSame(1, $resolutions);
    }

    /** @throws ConfigExceptionInterface on an unexpected source-resolution attempt */
    public function testReturnsNullForAMissingConfigurationWithoutResolvingIt(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('has');
        $container->expects(self::never())->method('get');
        $config = $this->config([], $container);

        self::assertNull($config->get(Stringable::class));
        self::assertFalse($config->has(Stringable::class));
        self::assertNull($config->get(Stringable::class));
    }

    /** @throws Throwable When loading a concrete source fails */
    public function testAnExplicitConcreteContractRetainsItsCompatibleSource(): void
    {
        $source = new stdClass();
        $config = $this->config([
            stdClass::class => stdClass::class,
        ], $this->container(static fn(): object => $source));

        self::assertSame($source, $config->get(stdClass::class));
        self::assertSame($source, $config->get(stdClass::class, 'default'));
    }

    public function testRejectsANonexistentRegistryContract(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('must name an existing class or interface');

        $this->config([
            'MissingConfigurationContract' => stdClass::class,
        ]);
    }

    public function testRejectsAnIncompatibleSourceDeclaration(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('must be an instantiable class compatible with');

        $this->config([
            Stringable::class => stdClass::class,
        ]);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testWrapsForeignSourceResolutionFailures(): void
    {
        $configuration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'unavailable';
            }
        };
        $failure = new RuntimeException('container failed');
        $config = $this->config([
            Stringable::class => $configuration::class,
        ], $this->container(static function () use ($failure): never {
            throw $failure;
        }));

        try {
            $config->get(Stringable::class);
            self::fail('A source resolution failure must be wrapped.');
        } catch (ConfigurationLoadingException $exception) {
            self::assertSame($failure, $exception->getPrevious());
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
        $config = $this->config([
            Stringable::class => $configuration::class,
        ], $this->container(static fn(): object => new stdClass()));

        $this->expectException(ConfigurationLoadingException::class);
        $this->expectExceptionMessageIsOrContains('resolved to stdClass');

        $config->get(Stringable::class);
    }

    /** @throws ConfigExceptionInterface when a corrected source cannot be loaded */
    public function testRejectsAnotherImplementationOfTheSameContractAndRetries(): void
    {
        $configuration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'declared';
            }
        };
        $replacement = new class() implements Stringable {
            public function __toString(): string
            {
                return 'undeclared';
            }
        };
        $attempts = 0;
        $container = $this->container(static function (string $id) use (
            $configuration,
            $replacement,
            &$attempts,
        ): object {
            self::assertSame($configuration::class, $id);

            return 1 === ++$attempts ? $replacement : $configuration;
        });
        $config = $this->config([
            Stringable::class => $configuration::class,
        ], $container);

        try {
            $config->get(Stringable::class);
            self::fail('An unrelated implementation must not replace the declared source.');
        } catch (ConfigurationLoadingException $exception) {
            self::assertStringContainsString($configuration::class, $exception->getMessage());
            self::assertStringContainsString('resolved to ' . get_debug_type($replacement), $exception->getMessage());
        }

        self::assertTrue($config->has(Stringable::class));
        self::assertSame($configuration, $config->get(Stringable::class));
        self::assertSame($configuration, $config->get(Stringable::class));
        self::assertSame(2, $attempts);
    }

    /** @throws ConfigExceptionInterface when a compatible source subclass cannot be loaded */
    public function testAcceptsACompatibleSubclassOfTheDeclaredSource(): void
    {
        $configuration = new class() extends ArrayObject {
        };
        $config = $this->config([
            Countable::class => ArrayObject::class,
        ], $this->container(static fn(): object => $configuration));

        self::assertSame($configuration, $config->get(Countable::class));
    }

    /** @throws Throwable when an interleaved source cannot complete as expected */
    #[DataProvider('firstSourceOutcomes')]
    public function testKeepsPendingContractsActiveWhenAnotherSourceFinishes(bool $failFirst): void
    {
        $stringConfiguration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'string';
            }
        };
        $countConfiguration = new class() implements Countable {
            public function count(): int
            {
                return 1;
            }
        };
        $attempts = 0;
        $container = $this->container(static function (string $id) use (
            $stringConfiguration,
            $countConfiguration,
            $failFirst,
            &$attempts,
        ): object {
            if ($stringConfiguration::class === $id) {
                if (1 === ++$attempts) {
                    Fiber::suspend();

                    if ($failFirst) {
                        throw new RuntimeException('temporarily unavailable');
                    }
                }

                return $stringConfiguration;
            }

            self::assertSame($countConfiguration::class, $id);
            Fiber::suspend();

            return $countConfiguration;
        });
        $config = $this->config([
            Stringable::class => $stringConfiguration::class,
            Countable::class  => $countConfiguration::class,
        ], $container);
        $first = new Fiber(static fn(): object => $config->get(Stringable::class));
        $second = new Fiber(static fn(): object => $config->get(Countable::class));
        $first->start();
        $second->start();

        try {
            if ($failFirst) {
                try {
                    $first->resume();
                    self::fail('The first source must report its transient failure.');
                } catch (ConfigurationLoadingException $exception) {
                    self::assertSame('temporarily unavailable', $exception->getPrevious()?->getMessage());
                }
            } else {
                $first->resume();
                self::assertSame($stringConfiguration, $first->getReturn());
            }

            self::assertSame($stringConfiguration, $config->get(Stringable::class));
            self::assertSame($failFirst ? 2 : 1, $attempts);

            try {
                $config->get(Countable::class);
                self::fail('The second source must remain marked as resolving until it completes.');
            } catch (CircularConfigurationDependencyException $exception) {
                self::assertSame(
                    'Circular configuration dependency detected: ' . Countable::class . ' -> ' . Countable::class . '.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($second->isSuspended()) {
                $second->resume();
            }
        }

        self::assertSame($countConfiguration, $second->getReturn());
        self::assertSame($countConfiguration, $config->get(Countable::class));
    }

    /** @return iterable<string, array{bool}> */
    public static function firstSourceOutcomes(): iterable
    {
        yield 'success' => [
            false,
        ];
        yield 'failure and retry' => [
            true,
        ];
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testDetectsAReentrantConfigurationCycle(): void
    {
        $configuration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'cyclic';
            }
        };
        $config = null;
        $container = $this->container(static function () use (&$config): object {
            self::assertInstanceOf(Config::class, $config);

            return $config->get(Stringable::class);
        });
        $config = $this->config([
            Stringable::class => $configuration::class,
        ], $container);

        $this->expectException(CircularConfigurationDependencyException::class);
        $this->expectExceptionMessageIsOrContains(Stringable::class . ' -> ' . Stringable::class);

        $config->get(Stringable::class);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testPreservesACycleWrappedByTheSourceContainer(): void
    {
        $configuration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'cyclic';
            }
        };
        $config = null;
        $container = $this->container(static function () use (&$config): object {
            self::assertInstanceOf(Config::class, $config);

            try {
                return $config->get(Stringable::class);
            } catch (CircularConfigurationDependencyException $exception) {
                throw new RuntimeException('container wrapped the cycle', 0, $exception);
            }
        });
        $config = $this->config([
            Stringable::class => $configuration::class,
        ], $container);

        $this->expectException(CircularConfigurationDependencyException::class);

        $config->get(Stringable::class);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testReportsTheCompleteIndirectConfigurationCycle(): void
    {
        $stringConfiguration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'string';
            }
        };
        $countConfiguration = new class() implements Countable {
            public function count(): int
            {
                return 1;
            }
        };
        $config = null;
        $container = $this->container(static function (string $id) use (
            &$config,
            $stringConfiguration,
            $countConfiguration,
        ): object {
            self::assertInstanceOf(Config::class, $config);

            return match ($id) {
                $stringConfiguration::class => $config->get(Countable::class),
                $countConfiguration::class  => $config->get(Stringable::class),
                default                     => throw new RuntimeException('Unexpected configuration source.'),
            };
        });
        $config = $this->config([
            Stringable::class => $stringConfiguration::class,
            Countable::class  => $countConfiguration::class,
        ], $container);

        $this->expectException(CircularConfigurationDependencyException::class);
        $this->expectExceptionMessageIsOrContains(
            Stringable::class . ' -> ' . Countable::class . ' -> ' . Stringable::class,
        );

        $config->get(Stringable::class);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRetriesAfterATransientSourceFailure(): void
    {
        $configuration = new class() implements Stringable {
            public function __toString(): string
            {
                return 'available';
            }
        };
        $attempts = 0;
        $container = $this->container(static function () use ($configuration, &$attempts): object {
            ++$attempts;

            if (1 === $attempts) {
                throw new RuntimeException('temporarily unavailable');
            }

            return $configuration;
        });
        $config = $this->config([
            Stringable::class => $configuration::class,
        ], $container);

        try {
            $config->get(Stringable::class);
            self::fail('The first transient source failure must be reported.');
        } catch (ConfigurationLoadingException $exception) {
            self::assertSame('temporarily unavailable', $exception->getPrevious()?->getMessage());
        }

        self::assertTrue($config->has(Stringable::class));
        self::assertSame(1, $attempts);
        self::assertSame($configuration, $config->get(Stringable::class));
        self::assertSame(2, $attempts);
    }

    private function container(?callable $resolver = null): ContainerInterface
    {
        $container = $this->createStub(ContainerInterface::class);

        if (null !== $resolver) {
            $container->method('get')->willReturnCallback($resolver);
        }

        return $container;
    }

    /**
     * @param array<class-string, class-string> $sources
     */
    private function config(array $sources, ?ContainerInterface $container = null): Config
    {
        return new Config($sources, $container ?? $this->container(), new ReflectorProvider()());
    }
}
