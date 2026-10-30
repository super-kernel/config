<?php

declare(strict_types=1);

namespace SuperKernel\Config\Provider;

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
    /**
     * @throws InvalidConfigurationException When a Configuration source cannot be reflected or instantiated, its
     *                                       contract is missing, incompatible, or ambiguous, or two sources declare
     *                                       the same contract
     */
    public function __invoke(
        AnnotationCollectorInterface $annotationCollector,
        ReflectorInterface           $reflector,
    ): ConfigInterface
    {
        /** @var array<class-string, class-string> $declarations */
        $declarations = [];

        foreach (
            $annotationCollector->getClassesWithAttribute(Configuration::class) as $implementation => [$metadata]
        ) {
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

            $interface = $metadata->attribute()->className;

            if (null === $interface) {
                if ($reflection->implementsInterface(MultipleConfigInterface::class)) {
                    throw new InvalidConfigurationException(sprintf(
                        'Multiple configuration source %s must explicitly name its item contract in Configuration.',
                        $reflection->getName(),
                    ));
                }

                $interfaces = array_filter(
                    $reflection->getInterfaceNames(),
                    static fn(string $interfaceName): bool => SingleConfigInterface::class !== $interfaceName,
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
                        $reflection->getName(),
                        Configuration::class,
                        count($mostSpecific),
                        [] === $mostSpecific ? '' : ': ' . implode(', ', $mostSpecific),
                    ));
                }

                $interface = $mostSpecific[0];
            } elseif (!interface_exists($interface) && !class_exists($interface)) {
                throw new InvalidConfigurationException(sprintf(
                    'Configuration source %s explicitly names %s, which is not an existing class or interface.',
                    $reflection->getName(),
                    $interface,
                ));
            } elseif (
                !is_a($reflection->getName(), $interface, true)
                && !$reflection->implementsInterface(MultipleConfigInterface::class)
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'Configuration source %s must be compatible with its declared contract %s.',
                    $reflection->getName(),
                    $interface,
                ));
            }

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

        return make(Config::class, [
            'sources' => $declarations,
        ]);
    }
}
