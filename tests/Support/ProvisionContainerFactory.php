<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Support;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use SuperKernel;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Di\Container;

use function array_map;
use function array_values;
use function class_implements;
use function class_parents;
use function ksort;

use const SORT_STRING;

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
            $record = self::declaredAttributes($class);
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

    /**
     * Collects the Attributes a build records for one class: its own, and those of the methods and properties it
     * declares in member-name order, each with the Attribute's parent classes and interfaces as captured types.
     *
     * @param class-string $class
     *
     * @return array{
     *     classes: array<class-string, list<AttributeMetadata<object>>>,
     *     methods: array<class-string, array<non-empty-string, list<AttributeMetadata<object>>>>,
     *     properties: array<class-string, array<non-empty-string, list<AttributeMetadata<object>>>>
     * }
     *
     * @throws ReflectionException When the class cannot be reflected
     */
    public static function declaredAttributes(string $class): array
    {
        $metadata = static function (ReflectionAttribute $attribute): AttributeMetadata {
            $instance = $attribute->newInstance();

            return new AttributeMetadata($instance, [
                ...array_values(class_parents($instance)),
                ...array_values(class_implements($instance)),
            ]);
        };
        $reflection = new ReflectionClass($class);
        $record = [
            'classes'    => [],
            'methods'    => [],
            'properties' => [],
        ];
        $attributes = $reflection->getAttributes();

        if ([] !== $attributes) {
            $record['classes'][$class] = array_map($metadata, $attributes);
        }

        foreach ([
            'methods'    => $reflection->getMethods(),
            'properties' => $reflection->getProperties(),
        ] as $kind => $members) {
            foreach ($members as $member) {
                $attributes = $member->getAttributes();

                if ($class === $member->getDeclaringClass()->getName() && [] !== $attributes) {
                    $record[$kind][$class][$member->getName()] = array_map($metadata, $attributes);
                }
            }

            if (isset($record[$kind][$class])) {
                ksort($record[$kind][$class], SORT_STRING);
            }
        }

        return $record;
    }
}
