<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use stdClass;
use Stringable;
use SuperKernel;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Annotator\Scan\ClassAnnotationScanner;
use SuperKernel\Attribute\Provision;
use SuperKernel\Config\Collector\ProvisionCollector;
use SuperKernel\Config\Tests\Fixture\ProvisionMethods;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\Reflector\Reflector;

use function array_keys;
use function class_exists;

final class ProvisionCollectorTest extends TestCase
{
    /** @throws ReflectionException When the indexed provision method cannot be reflected */
    public function testUsesTheMethodIndexWithoutReadingPackageRecords(): void
    {
        $metadata = new AttributeMetadata(new Provision('indexed'));
        $annotations = $this->createMock(AnnotationCollectorInterface::class);
        $annotations->expects(self::never())->method('getPackages');
        $annotations->expects(self::once())->method('getMethodsWithAttribute')->with(Provision::class)->willReturn([
            ProvisionMethods::class => [
                'Primary' => [
                    $metadata,
                ],
            ],
        ]);
        $collector = new ProvisionCollector($annotations, new Reflector());

        self::assertSame(
            $metadata->attribute(),
            $collector->getMethods()[ProvisionMethods::class]['Primary']['attribute'],
        );
        self::assertCount(1, $collector->getMethods()[ProvisionMethods::class]);
    }

    /** @throws ReflectionException When the provision fixtures cannot be reflected */
    public function testCollectsMethodsAcrossPackagesAndRetainsTrustedAttributes(): void
    {
        $other = new class() {
            /** @throws LogicException If collection incorrectly invokes this method */
            #[Provision('other')]
            public function Primary(): stdClass
            {
                throw new LogicException('Provision methods must not run during collection.');
            }
        };
        $annotations = new AnnotationCollector([
            $this->package('fixture/application', ProvisionMethods::class),
            $this->package('fixture/dependency', $other::class),
            new SuperKernel\Annotator\Model\PackageAnnotations('fixture/duplicate', null, [], [
                SuperKernel\Config\Tests\Fixture\ProvisionMethods::class => [
                    'Primary' => [
                        new SuperKernel\Annotator\Model\AttributeMetadata(
                            new SuperKernel\Attribute\Provision('shadow'),
                        ),
                    ],
                ],
            ], []),
        ]);
        $collector = new ProvisionCollector($annotations, new Reflector());
        $methods = $collector->getMethods();

        self::assertSame([
            SuperKernel\Config\Tests\Fixture\ProvisionMethods::class,
            $other::class,
        ], array_keys($methods));
        self::assertSame(
            $annotations->getMethodAttributes(ProvisionMethods::class, 'Primary')[1]->attribute(),
            $methods[ProvisionMethods::class]['Primary']['attribute'],
        );
        self::assertSame([
            'primary',
            'alias',
            'primary',
        ], $methods[ProvisionMethods::class]['Primary']['attribute']->name);
        self::assertSame([
            'other',
        ], $methods[$other::class]['Primary']['attribute']->name);
        self::assertArrayNotHasKey('primary', $methods[ProvisionMethods::class]);
        self::assertArrayNotHasKey('unrelated', $methods[ProvisionMethods::class]);
        self::assertArrayNotHasKey('unmarked', $methods[ProvisionMethods::class]);
        self::assertCount(9, $methods[ProvisionMethods::class]);
        $type = $methods[$other::class]['Primary']['returnType'];
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(stdClass::class, $type->getName());
    }

    /** @throws ReflectionException When the provision fixture cannot be reflected */
    public function testScansDuringConstructionAndDoesNotRescanOnReads(): void
    {
        $scanned = [];
        $reflector = $this->createMock(ReflectorInterface::class);
        $reflector->expects(self::exactly(9))->method('reflectMethod')->willReturnCallback(
            static function (string $class, string $method) use (&$scanned): ReflectionMethod {
                $scanned[] = $method;

                return new ReflectionMethod($class, $method);
            },
        );
        $collector = new ProvisionCollector(new AnnotationCollector([
            $this->package('fixture/application', ProvisionMethods::class),
        ]), $reflector);

        self::assertSame([
            'Primary',
            'builtin',
            'contextual',
            'disjunctive',
            'intersection',
            'lateStatic',
            'nullable',
            'union',
            'untyped',
        ], $scanned);
        self::assertSame($collector->getMethods(), $collector->getMethods());
    }

    /** @throws ReflectionException When the provision fixture cannot be reflected */
    public function testPreservesNativeReturnTypesAndMissingDeclarations(): void
    {
        $collector = new ProvisionCollector(new AnnotationCollector([
            $this->package('fixture/application', ProvisionMethods::class),
        ]), new Reflector());
        $methods = $collector->getMethods()[ProvisionMethods::class];

        $primary = $methods['Primary']['returnType'];
        self::assertInstanceOf(ReflectionNamedType::class, $primary);
        self::assertSame(Stringable::class, $primary->getName());
        self::assertFalse($primary->allowsNull());
        $nullable = $methods['nullable']['returnType'];
        self::assertInstanceOf(ReflectionNamedType::class, $nullable);
        self::assertTrue($nullable->allowsNull());
        self::assertInstanceOf(ReflectionUnionType::class, $methods['union']['returnType']);
        self::assertInstanceOf(ReflectionIntersectionType::class, $methods['intersection']['returnType']);
        $disjunctive = $methods['disjunctive']['returnType'];
        self::assertInstanceOf(ReflectionUnionType::class, $disjunctive);
        self::assertInstanceOf(ReflectionIntersectionType::class, $disjunctive->getTypes()[0]);
        self::assertSame('self', (string) $methods['contextual']['returnType']);
        self::assertSame('static', (string) $methods['lateStatic']['returnType']);
        $builtin = $methods['builtin']['returnType'];
        self::assertInstanceOf(ReflectionNamedType::class, $builtin);
        self::assertSame('string', $builtin->getName());
        self::assertTrue($builtin->isBuiltin());
        self::assertNull($methods['untyped']['returnType']);
        self::assertSame([], $methods['untyped']['attribute']->name);
    }

    /** @throws ReflectionException If an empty collection unexpectedly reflects a method */
    public function testEmptyMetadataDoesNotReflectMethods(): void
    {
        $reflector = $this->createMock(ReflectorInterface::class);
        $reflector->expects(self::never())->method('reflectMethod');

        self::assertSame([], new ProvisionCollector(new AnnotationCollector([]), $reflector)->getMethods());
    }

    /** @throws ReflectionException If unrelated metadata unexpectedly reflects a method */
    public function testUnrelatedAttributesDoNotLoadOrReflectTheirClasses(): void
    {
        $reflector = $this->createMock(ReflectorInterface::class);
        $reflector->expects(self::never())->method('reflectMethod');
        $collector = new ProvisionCollector(new AnnotationCollector([
            new SuperKernel\Annotator\Model\PackageAnnotations('fixture/unrelated', null, [], [
                'Acme\\Config\\UnloadedSource' => [
                    'unrelated' => [
                        new SuperKernel\Annotator\Model\AttributeMetadata(new SuperKernel\Attribute\Configuration()),
                    ],
                ],
            ], []),
        ]), $reflector);

        self::assertSame([], $collector->getMethods());
        self::assertFalse(class_exists('Acme\\Config\\UnloadedSource', false));
    }

    /** @throws ReflectionException When the collected method no longer exists */
    public function testReflectionFailuresAreReportedDuringConstruction(): void
    {
        $annotations = new AnnotationCollector([
            new SuperKernel\Annotator\Model\PackageAnnotations('fixture/missing', null, [], [
                SuperKernel\Config\Tests\Fixture\ProvisionMethods::class => [
                    'missing' => [
                        new SuperKernel\Annotator\Model\AttributeMetadata(new SuperKernel\Attribute\Provision()),
                    ],
                ],
            ], []),
        ]);
        $this->expectException(ReflectionException::class);
        $this->expectExceptionMessageIsOrContains('missing');

        new ProvisionCollector($annotations, new Reflector());
    }

    /**
     * @param non-empty-string $name
     * @param class-string     $class
     *
     * @throws ReflectionException When a fixture or its attributes cannot be reflected
     */
    private function package(string $name, string $class): PackageAnnotations
    {
        $reflector = new Reflector();
        $record = new ClassAnnotationScanner($reflector)->collect($reflector->reflectClass($class));

        return new PackageAnnotations($name, null, $record['classes'], $record['methods'], $record['properties']);
    }
}
