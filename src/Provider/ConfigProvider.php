<?php

declare(strict_types=1);

namespace SuperKernel\Config\Provider;

use ReflectionClass;
use ReflectionException;
use SuperKernel\Attribute\Configuration;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Config\Config;
use SuperKernel\Config\Exception\InvalidConfigurationException;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Contract\MultipleConfigInterface;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\Contract\SingleConfigInterface;

use function array_all;
use function array_filter;
use function array_values;
use function class_exists;
use function count;
use function implode;
use function interface_exists;
use function is_a;
use function is_subclass_of;
use function sort;
use function sprintf;
use function SuperKernel\Support\make;

#[FactoryProvider(ConfigInterface::class)]
final readonly class ConfigProvider
{
    public function __invoke(
        AnnotationCollectorInterface $annotationCollector,
        ReflectorInterface           $reflector,
    ): ConfigInterface
    {
        return make(Config::class, [
            'sources' => $this->declarations($annotationCollector, $reflector),
        ]);
    }

    /**
     * @return array<class-string, class-string>
     */
    private function declarations(
        AnnotationCollectorInterface $annotationCollector,
        ReflectorInterface           $reflector,
    ): array
    {
        $declarations = [];

        foreach (
            $annotationCollector->getClassesWithAttribute(Configuration::class) as $implementation => [$metadata]
        ) {
            $reflection = $this->implementationReflection($implementation, $reflector);
            $attribute = $metadata->attribute();
            $interface = null === $attribute->className
                ? $this->inferInterface($reflection)
                : $this->explicitContract($reflection, $attribute->className);

            if (isset($declarations[$interface])) {
                throw new InvalidConfigurationException(sprintf(
                    'Configuration contract %s is declared by both %s and %s.',
                    $interface,
                    $declarations[$interface],
                    $implementation,
                ));
            }

            $declarations[$interface] = $implementation;
        }

        return $declarations;
    }

    /**
     * @param class-string $implementation
     *
     * @return ReflectionClass<object>
     */
    private function implementationReflection(string $implementation, ReflectorInterface $reflector): ReflectionClass
    {
        try {
            $reflection = $reflector->reflectClass($implementation);
        } catch (ReflectionException $exception) {
            throw new InvalidConfigurationException(sprintf(
                'Configuration source %s cannot be reflected.',
                $implementation,
            ), 0, $exception);
        }

        if (!$reflection->isInstantiable()) {
            throw new InvalidConfigurationException(sprintf(
                'Configuration source %s must be an instantiable class.',
                $implementation,
            ));
        }

        return $reflection;
    }

    /**
     * @param ReflectionClass<object> $implementation
     *
     * @return class-string
     */
    private function explicitContract(ReflectionClass $implementation, string $interface): string
    {
        if (!interface_exists($interface) && !class_exists($interface)) {
            throw new InvalidConfigurationException(sprintf(
                'Configuration source %s explicitly names %s, which is not an existing class or interface.',
                $implementation->getName(),
                $interface,
            ));
        }

        if (
            !is_a($implementation->getName(), $interface, true)
            && !$implementation->implementsInterface(MultipleConfigInterface::class)
        ) {
            throw new InvalidConfigurationException(sprintf(
                'Configuration source %s must be compatible with its declared contract %s.',
                $implementation->getName(),
                $interface,
            ));
        }

        return $interface;
    }

    /**
     * @param ReflectionClass<object> $implementation
     *
     * @return class-string
     */
    private function inferInterface(ReflectionClass $implementation): string
    {
        if ($implementation->implementsInterface(MultipleConfigInterface::class)) {
            throw new InvalidConfigurationException(sprintf(
                'Multiple configuration source %s must explicitly name its item contract in Configuration.',
                $implementation->getName(),
            ));
        }

        $interfaces = array_filter(
            $implementation->getInterfaceNames(),
            static fn(string $interface): bool => SingleConfigInterface::class !== $interface,
        );
        $mostSpecific = array_values(array_filter(
            $interfaces,
            static fn(string $candidate): bool => array_all(
                $interfaces,
                static fn(string $other): bool => $candidate === $other
                    || !is_subclass_of($other, $candidate),
            ),
        ));

        sort($mostSpecific);

        if (1 !== count($mostSpecific)) {
            throw new InvalidConfigurationException(sprintf(
                'Configuration source %s must implement exactly one most-specific interface '
                . 'when %s has no explicit argument; found %d%s.',
                $implementation->getName(),
                Configuration::class,
                count($mostSpecific),
                [] === $mostSpecific ? '' : ': ' . implode(', ', $mostSpecific),
            ));
        }

        return $mostSpecific[0];
    }
}
