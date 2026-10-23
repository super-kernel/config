<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use stdClass;
use Stringable;
use SuperKernel;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Attribute\Provision;
use SuperKernel\Config\Definer\ProvisionDefiner;
use SuperKernel\Config\Exception\InvalidConfigurationException;
use SuperKernel\Config\Tests\Fixture\ProvisionFactory;
use SuperKernel\Config\Tests\Fixture\ProvisionProduct;
use SuperKernel\Config\Tests\Support\ProvisionContainerFactory;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Di\Exception\NotFoundException;

final class ProvisionDefinerTest extends TestCase
{
    public function testUsesTheMethodIndexWithoutReadingPackagesOrConstructingFactories(): void
    {
        ProvisionFactory::$constructions = 0;
        $annotations = $this->createMock(AnnotationCollectorInterface::class);
        $annotations->expects(self::never())->method('getPackages');
        $annotations->expects(self::once())->method('getMethodsWithAttribute')->with(Provision::class)->willReturn([
            ProvisionFactory::class => [
                'named' => [
                    new AttributeMetadata(new Provision('indexed')),
                ],
            ],
        ]);
        $definer = new ProvisionDefiner($annotations);

        self::assertTrue($definer->support('indexed'));
        self::assertSame('named', $definer->get('indexed')->methodName);
        self::assertSame(0, ProvisionFactory::$constructions);
    }

    /** @throws ReflectionException When fixture metadata cannot be collected */
    public function testDefinesInferredAndExplicitNamesWithoutConstructingTheFactory(): void
    {
        ProvisionFactory::$constructions = 0;
        $definer = new ProvisionDefiner(ProvisionContainerFactory::collector([
            SuperKernel\Config\Tests\Fixture\ProvisionFactory::class,
        ]));

        self::assertTrue($definer->support(ProvisionProduct::class));
        self::assertTrue($definer->support('primary'));
        self::assertTrue($definer->support('secondary'));
        self::assertFalse($definer->support('unknown'));
        self::assertSame(0, ProvisionFactory::$constructions);
        $definition = $definer->get(ProvisionProduct::class);
        self::assertSame(ProvisionProduct::class, $definition->identifier());
        self::assertSame(ProvisionFactory::class, $definition->factoryClass);
        self::assertSame('product', $definition->methodName);
        self::assertSame('named', $definer->get('primary')->methodName);
        self::assertSame('secondary', $definer->get('secondary')->identifier());
    }

    /** @throws ReflectionException When fixture metadata cannot be collected */
    public function testExplicitNamesReplaceTypeInferenceAndPermitScalarValues(): void
    {
        $factory = new class() {
            #[Provision('label')]
            public function label(): string
            {
                return 'named';
            }

            #[Provision('object')]
            public function object(): stdClass
            {
                return new stdClass();
            }
        };
        $definer = new ProvisionDefiner(ProvisionContainerFactory::collector([
            $factory::class,
        ]));

        self::assertTrue($definer->support('label'));
        self::assertTrue($definer->support('object'));
        self::assertFalse($definer->support(stdClass::class));
        self::assertFalse($definer->support('string'));
    }

    /** @throws ReflectionException When fixture metadata cannot be collected */
    public function testRejectsDifferentMethodsClaimingTheSameIdentifier(): void
    {
        $factory = new class() {
            #[Provision('duplicate')]
            public function first(): stdClass
            {
                return new stdClass();
            }

            #[Provision('duplicate')]
            public function second(): stdClass
            {
                return new stdClass();
            }
        };
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('is declared by both');

        new ProvisionDefiner(ProvisionContainerFactory::collector([
            $factory::class,
        ]));
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'abstract source' => [
            SuperKernel\Config\Tests\Fixture\ProvisionMethods::class,
            'instantiable class',
        ];
        yield 'private method' => [
            new class() {
                /**
                 * Reflection discovers this private method to verify factory accessibility is rejected.
                 *
                 * @noinspection PhpUnusedPrivateMethodInspection
                 */
                #[Provision('private')]
                private function hidden(): void
                {
                }
            }::class,
            'public and non-static',
        ];
        yield 'static method' => [
            new class() {
                #[Provision('static')]
                public static function create(): stdClass
                {
                    return new stdClass();
                }
            }::class,
            'public and non-static',
        ];
        yield 'nullable unnamed return' => [
            new class() {
                #[Provision]
                public function create(): ?stdClass
                {
                    return null;
                }
            }::class,
            'non-nullable class or interface',
        ];
        yield 'scalar unnamed return' => [
            new class() {
                #[Provision]
                public function create(): string
                {
                    return 'invalid';
                }
            }::class,
            'non-nullable class or interface',
        ];
        yield 'union unnamed return' => [
            new class() {
                #[Provision]
                public function create(): stdClass|Stringable
                {
                    return new stdClass();
                }
            }::class,
            'non-nullable class or interface',
        ];
        yield 'missing return type' => [
            new class() {
                /**
                 * An absent return declaration is the invalid metadata exercised by this case.
                 *
                 * @noinspection PhpMissingReturnTypeInspection
                 */
                #[Provision]
                public function create()
                {
                    return new stdClass();
                }
            }::class,
            'non-nullable class or interface',
        ];
        yield 'self-replacing factory' => [
            new class() {
                #[Provision]
                public function create(): self
                {
                    return $this;
                }
            }::class,
            'cannot replace its own source class',
        ];
    }

    /**
     * @param class-string $class
     *
     * @throws ReflectionException When fixture metadata cannot be collected
     */
    #[DataProvider('invalidDeclarations')]
    public function testRejectsInvalidDeclarationsBeforeResolution(string $class, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new ProvisionDefiner(ProvisionContainerFactory::collector([
            $class,
        ]));
    }

    /** @throws ReflectionException When bootstrap metadata cannot be collected */
    public function testUnknownIdentifiersHaveNoDefinition(): void
    {
        $definer = new ProvisionDefiner(ProvisionContainerFactory::collector());
        self::assertFalse($definer->support('missing'));
        $this->expectException(NotFoundException::class);

        $definer->get('missing');
    }
}
