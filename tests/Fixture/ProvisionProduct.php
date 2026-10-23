<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Fixture;

final readonly class ProvisionProduct
{
    public function __construct(
        public ConfigurationValue $constructorDependency,
        public ConfigurationValue $methodDependency,
        public string             $label = 'product',
    )
    {
    }
}
