<?php

declare(strict_types=1);

namespace SuperKernel\Config\Definer;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use SuperKernel\Attribute\Definer;
use SuperKernel\Attribute\Provision;
use SuperKernel\Config\Definition\ProvisionDefinition;
use SuperKernel\Config\Exception\InvalidConfigurationException;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Di\Contract\DefinerInterface;
use SuperKernel\Di\Exception\NotFoundException;

use function class_exists;
use function interface_exists;
use function sprintf;

#[Definer(priority: 50)]
final readonly class ProvisionDefiner implements DefinerInterface
{
    /** @var array<non-empty-string, ProvisionDefinition> */
    private array $definitions;

    /** @throws InvalidConfigurationException When provision declarations are invalid or identifiers conflict */
    public function __construct(AnnotationCollectorInterface $collector)
    {
        $definitions = [];
        foreach ($collector->getMethodsWithAttribute(Provision::class) as $class => $methods) {
            foreach ($methods as $name => [$metadata]) {
                // Definers bootstrap before container service resolution is available.
                try {
                    $reflection = new ReflectionClass($class);
                    $method = $reflection->getMethod($name);
                } catch (ReflectionException $exception) {
                    throw new InvalidConfigurationException(sprintf(
                        'Unable to reflect provision method %s::%s.',
                        $class,
                        $name,
                    ), 0, $exception);
                }

                if (
                    !$reflection->isInstantiable()
                    || !$method->isPublic()
                    || $method->isStatic()
                    || $method->isConstructor()
                    || $method->isDestructor()
                ) {
                    throw new InvalidConfigurationException(sprintf(
                        'Provision method %s::%s must be public and non-static on an instantiable class, '
                        . 'and cannot be a constructor or destructor.',
                        $class,
                        $name,
                    ));
                }

                $identifiers = $metadata->attribute()->name;
                if ([] === $identifiers) {
                    $type = $method->getReturnType();
                    $declaringClass = $method->getDeclaringClass();
                    $identifier = null;
                    if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && !$type->allowsNull()) {
                        $parent = $declaringClass->getParentClass();
                        $identifier = match ($type->getName()) {
                            'self', 'static' => $declaringClass->getName(),
                            'parent'         => false === $parent ? null : $parent->getName(),
                            default          => $type->getName(),
                        };
                    }

                    if (null === $identifier || (!class_exists($identifier) && !interface_exists($identifier))) {
                        throw new InvalidConfigurationException(sprintf(
                            'Unnamed provision %s::%s must declare a non-nullable class or interface return type; '
                            . 'otherwise specify Provision names explicitly.',
                            $declaringClass->getName(),
                            $method->getName(),
                        ));
                    }

                    $identifiers = [
                        $identifier,
                    ];
                }

                foreach ($identifiers as $id) {
                    if ($class === $id) {
                        throw new InvalidConfigurationException(sprintf(
                            'Provision %s::%s cannot replace its own source class; use an explicit service name.',
                            $class,
                            $name,
                        ));
                    }

                    $previous = $definitions[$id] ?? null;
                    if (null !== $previous) {
                        if ($class === $previous->factoryClass && $name === $previous->methodName) {
                            continue;
                        }

                        throw new InvalidConfigurationException(sprintf(
                            'Provision for %s is declared by both %s::%s and %s::%s.',
                            $id,
                            $previous->factoryClass,
                            $previous->methodName,
                            $class,
                            $name,
                        ));
                    }

                    $definitions[$id] = new ProvisionDefinition($id, $class, $name);
                }
            }
        }

        $this->definitions = $definitions;
    }

    public function support(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    /** @throws NotFoundException When the identifier has no provision method */
    public function get(string $id): ProvisionDefinition
    {
        return $this->definitions[$id] ?? throw NotFoundException::forIdentifier($id);
    }
}
