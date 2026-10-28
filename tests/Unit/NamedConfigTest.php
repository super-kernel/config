<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Unit;

use Closure;
use Countable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Stringable;
use SuperKernel\Attribute\Configuration;
use SuperKernel\Config\Config;
use SuperKernel\Config\Exception\CircularConfigurationDependencyException;
use SuperKernel\Config\Exception\ConfigurationLoadingException;
use SuperKernel\Config\Exception\ConfigurationNotFoundException;
use SuperKernel\Config\Exception\InvalidConfigurationException;
use SuperKernel\Config\Tests\Fixture\ConfigurationValue;
use SuperKernel\Config\Tests\Fixture\MultipleApplicationConfig;
use SuperKernel\Contract\MultipleConfigInterface;
use SuperKernel\Contract\SingleConfigInterface;
use SuperKernel\Reflector\Provider\ReflectorProvider;
use Throwable;

final class NamedConfigTest extends TestCase
{
    /** @throws Throwable When configuration loading fails */
    public function testSingleConfigurationUsesTheDefaultNameAndRetainsTheSource(): void
    {
        $source = new ConfigurationValue();
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with($source::class)->willReturn($source);
        $config = new Config([
            Stringable::class => $source::class,
        ], $container, new ReflectorProvider()());

        self::assertTrue($config->has(Stringable::class));
        self::assertTrue($config->has(Stringable::class, 'default'));
        self::assertFalse($config->has(Stringable::class, 'missing'));
        self::assertNull($config->get(Stringable::class, 'missing'));
        self::assertSame($source, $config->get(Stringable::class));
        self::assertSame($source, $config->get(Stringable::class, 'default'));
    }

    /** @throws Throwable When configuration loading fails */
    public function testMultipleItemsLoadOnlyWhenRequestedAndShareTheDefaultIdentity(): void
    {
        $source = new MultipleApplicationConfig();
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with($source::class)->willReturn($source);
        $config = new Config([
            Stringable::class => $source::class,
        ], $container, new ReflectorProvider()());

        self::assertTrue($config->has(Stringable::class));
        self::assertTrue($config->has(Stringable::class, 'primary'));
        self::assertTrue($config->has(Stringable::class, 'cache'));
        self::assertFalse($config->has(Stringable::class, 'default'));
        self::assertFalse($config->has(Stringable::class, 'Cache'));
        self::assertNull($config->get(Stringable::class, 'missing'));
        self::assertSame([], $source->calls);

        $cache = $config->get(Stringable::class, 'cache');
        self::assertSame('cache', (string) $cache);
        self::assertSame([
            'cache' => 1,
        ], $source->calls);
        self::assertSame($cache, $config->get(Stringable::class, 'cache'));
        $default = $config->get(Stringable::class);
        self::assertSame('primary', (string) $default);
        self::assertSame($default, $config->get(Stringable::class, 'primary'));
        self::assertNotSame($cache, $default);
        self::assertSame([
            'cache'   => 1,
            'primary' => 1,
        ], $source->calls);
    }

    /** @throws Throwable When item loading fails */
    public function testAnItemFailureKeepsItsCauseAndCanBeRetriedWithoutReloadingTheSource(): void
    {
        $cause = new RuntimeException('temporary item failure');
        $source = new class($cause) implements MultipleConfigInterface {
            public int $attempts = 0;

            public function __construct(private readonly RuntimeException $cause)
            {
            }

            #[Configuration(default: true)]
            public function primary(): Stringable
            {
                if (1 === ++$this->attempts) {
                    throw $this->cause;
                }

                return new ConfigurationValue();
            }
        };
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->willReturn($source);
        $config = new Config([
            Stringable::class => $source::class,
        ], $container, new ReflectorProvider()());

        try {
            $config->get(Stringable::class);
            self::fail('A failed method must not become a resolved item.');
        } catch (ConfigurationLoadingException $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }

        self::assertTrue($config->has(Stringable::class));
        $item = $config->get(Stringable::class);
        self::assertSame($item, $config->get(Stringable::class, 'primary'));
        self::assertSame(2, $source->attempts);
    }

    /** @throws Throwable When item loading detects the intended cycle */
    public function testCyclesIncludeItemNamesAndClearTheActivePathAfterFailure(): void
    {
        $config = null;
        $resolve = static function (?string $name) use (&$config): Stringable {
            self::assertInstanceOf(Config::class, $config);

            return $config->get(Stringable::class, $name);
        };
        $source = new class($resolve) implements MultipleConfigInterface {
            public bool $cyclic = true;

            /** @param Closure(?string): Stringable $resolve */
            public function __construct(private readonly Closure $resolve)
            {
            }

            #[Configuration(default: true)]
            public function primary(): Stringable
            {
                return $this->cyclic ? ($this->resolve)('cache') : new ConfigurationValue();
            }

            #[Configuration]
            public function cache(): Stringable
            {
                return ($this->resolve)(null);
            }
        };
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->willReturn($source);
        $config = new Config([
            Stringable::class => $source::class,
        ], $container, new ReflectorProvider()());

        try {
            $config->get(Stringable::class);
            self::fail('The default alias must participate in item cycle detection.');
        } catch (CircularConfigurationDependencyException $exception) {
            self::assertStringContainsString(
                'Stringable::primary -> Stringable::cache -> Stringable::primary',
                $exception->getMessage(),
            );
        }

        $source->cyclic = false;
        self::assertSame($config->get(Stringable::class), $config->get(Stringable::class, 'cache'));
    }

    /** @throws Throwable When the sole item cannot be loaded */
    public function testASoleMethodItemBecomesTheDefaultWithoutAnExplicitFlag(): void
    {
        $source = new class() implements MultipleConfigInterface {
            #[Configuration]
            public function primary(): Stringable
            {
                return new ConfigurationValue();
            }
        };
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->willReturn($source);
        $config = new Config([
            Stringable::class => $source::class,
        ], $container, new ReflectorProvider()());

        self::assertSame($config->get(Stringable::class, 'primary'), $config->get(Stringable::class));
    }

    /** @throws Throwable When a named configuration item cannot be loaded */
    public function testSeveralItemsWithoutADefaultRemainUsableByNameAndOnlyDefaultLookupFails(): void
    {
        $source = new class() implements MultipleConfigInterface {
            #[Configuration]
            public function default(): Stringable
            {
                return new ConfigurationValue('primary');
            }

            #[Configuration]
            public function cache(): Stringable
            {
                return new ConfigurationValue('cache');
            }
        };
        $loads = 0;
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->willReturnCallback(static function () use ($source, &$loads) {
            ++$loads;

            return $source;
        });
        $config = new Config([
            Stringable::class => $source::class,
        ], $container, new ReflectorProvider()());

        self::assertTrue($config->has(Stringable::class));
        self::assertTrue($config->has(Stringable::class, 'cache'));
        self::assertNull($config->get(Stringable::class, 'missing'));
        try {
            $config->get(Stringable::class);
            self::fail('Only an unnamed lookup requires a declared default among several items.');
        } catch (ConfigurationNotFoundException $exception) {
            self::assertStringContainsString('no default item', $exception->getMessage());
        }
        self::assertSame(0, $loads);
        self::assertSame('cache', (string) $config->get(Stringable::class, 'cache'));
        self::assertSame('primary', (string) $config->get(Stringable::class, 'default'));
    }

    /** @return iterable<string, array{object}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'two defaults' => [
            new class() implements MultipleConfigInterface {
                #[Configuration(default: true)]
                public function primary(): Stringable
                {
                    return new ConfigurationValue();
                }

                #[Configuration(default: true)]
                public function cache(): Stringable
                {
                    return new ConfigurationValue();
                }
            },
        ];
        yield 'repeated Configuration' => [
            new class() implements MultipleConfigInterface {
                /**
                 * Deliberately repeats metadata to verify declaration rejection.
                 *
                 * @noinspection PhpAttributeIsNotRepeatableInspection
                 */
                #[Configuration]
                #[Configuration]
                public function primary(): Stringable
                {
                    return new ConfigurationValue();
                }
            },
        ];
        yield 'different method contract' => [
            new class() implements MultipleConfigInterface {
                #[Configuration(Countable::class)]
                public function primary(): Stringable
                {
                    return new ConfigurationValue();
                }
            },
        ];
        yield 'private Configuration method' => [
            new class() implements MultipleConfigInterface {
                /**
                 * Reflection inspects this method to verify private configuration items are rejected.
                 *
                 * @noinspection PhpUnusedPrivateMethodInspection
                 */
                #[Configuration]
                private function primary(): Stringable
                {
                    return new ConfigurationValue();
                }
            },
        ];
        yield 'empty source' => [
            new class() implements MultipleConfigInterface {
            },
        ];
        yield 'both modes' => [
            new class() implements
                MultipleConfigInterface,
                SingleConfigInterface {
            },
        ];
        yield 'static method' => [
            new class() implements MultipleConfigInterface {
                #[Configuration(default: true)]
                public static function primary(): Stringable
                {
                    return new ConfigurationValue();
                }
            },
        ];
        yield 'method argument' => [
            new class() implements MultipleConfigInterface {
                #[Configuration(default: true)]
                public function primary(string $value = 'optional'): Stringable
                {
                    return new ConfigurationValue($value);
                }
            },
        ];
        yield 'wrong return type' => [
            new class() implements MultipleConfigInterface {
                #[Configuration(default: true)]
                public function primary(): int
                {
                    return 1;
                }
            },
        ];
        yield 'nullable return type' => [
            new class() implements MultipleConfigInterface {
                #[Configuration(default: true)]
                public function primary(): ?Stringable
                {
                    return null;
                }
            },
        ];
        yield 'unmarked method' => [
            new class() implements MultipleConfigInterface {
                public function primary(): Stringable
                {
                    return new ConfigurationValue();
                }
            },
        ];
    }

    #[DataProvider('invalidDeclarations')]
    public function testInvalidItemDeclarationsFailBeforeLoadingAnySource(object $source): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $this->expectException(InvalidConfigurationException::class);

        new Config([
            Stringable::class => $source::class,
        ], $container, new ReflectorProvider()());
    }
}
