<?php

declare(strict_types=1);

namespace SuperKernel\Config\Definition;

use SuperKernel\Di\Contract\DefinitionInterface;

final readonly class ProvisionDefinition implements DefinitionInterface
{
    /**
     * @param non-empty-string $id
     * @param class-string     $factoryClass
     * @param non-empty-string $methodName
     */
    public function __construct(public string $id, public string $factoryClass, public string $methodName)
    {
    }

    public function identifier(): string
    {
        return $this->id;
    }
}
