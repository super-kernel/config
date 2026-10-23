<?php

declare(strict_types=1);

namespace SuperKernel\Config\Collector;

use ReflectionException;
use ReflectionType;
use SuperKernel\Attribute\Provision;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\ReflectorInterface;

final readonly class ProvisionCollector
{
    /**
     * @var array<class-string, array<non-empty-string, array{attribute: Provision, returnType: ReflectionType|null}>>
     */
    private array $methods;

    /** @throws ReflectionException When a collected provision method cannot be reflected */
    public function __construct(AnnotationCollectorInterface $collector, ReflectorInterface $reflector)
    {
        $methods = [];
        foreach ($collector->getMethodsWithAttribute(Provision::class) as $class => $members) {
            foreach ($members as $name => [$metadata]) {
                $method = $reflector->reflectMethod($class, $name);
                $methods[$class][$method->getName()] = [
                    'attribute'  => $metadata->attribute(),
                    'returnType' => $method->getReturnType(),
                ];
            }
        }

        $this->methods = $methods;
    }

    /**
     * Returns provision metadata scanned during construction, keyed by class and exact method name.
     *
     * @return array<class-string, array<non-empty-string, array{
     *     attribute: Provision,
     *     returnType: ReflectionType|null,
     * }>>
     */
    public function getMethods(): array
    {
        return $this->methods;
    }
}
