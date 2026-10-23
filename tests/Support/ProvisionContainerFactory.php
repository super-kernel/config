<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Support;

use ReflectionException;
use SuperKernel;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Scan\ClassAnnotationScanner;
use SuperKernel\Di\Container;
use SuperKernel\Reflector\Reflector;

final class ProvisionContainerFactory
{
    /**
     * @param list<class-string> $classes
     *
     * @throws ReflectionException When a test declaration cannot be collected
     */
    public static function create(array $classes = []): Container
    {
        return new Container(self::collector($classes));
    }

    /**
     * @param list<class-string> $classes
     *
     * @throws ReflectionException When a test declaration cannot be collected
     */
    public static function collector(array $classes = []): AnnotationCollector
    {
        $reflector = new Reflector();
        $scanner = new ClassAnnotationScanner($reflector);
        $classAttributes = [];
        $methodAttributes = [];
        $propertyAttributes = [];

        foreach ([
            SuperKernel\Di\Definer\ProviderDefiner::class,
            SuperKernel\Di\Definer\FactoryDefiner::class,
            SuperKernel\Di\Definer\ClassDefiner::class,
            SuperKernel\Di\Resolver\FactoryResolver::class,
            SuperKernel\Di\Resolver\ProviderResolver::class,
            SuperKernel\Di\Resolver\ClassResolver::class,
            SuperKernel\Di\Resolver\PropertyResolver::class,
            SuperKernel\Di\Resolver\MethodResolver::class,
            SuperKernel\Reflector\Provider\ReflectorProvider::class,
            SuperKernel\Config\Definer\ProvisionDefiner::class,
            SuperKernel\Config\Resolver\ProvisionResolver::class,
            ...$classes,
        ] as $class) {
            $record = $scanner->collect($reflector->reflectClass($class));
            $classAttributes += $record['classes'];
            $methodAttributes += $record['methods'];
            $propertyAttributes += $record['properties'];
        }

        return new AnnotationCollector([
            new SuperKernel\Annotator\Model\PackageAnnotations(
                'fixture/provision',
                null,
                $classAttributes,
                $methodAttributes,
                $propertyAttributes,
            ),
        ]);
    }
}
