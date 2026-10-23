<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Fixture\Provider;

use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Config\Tests\Fixture\ConfigurationValue;
use SuperKernel\Config\Tests\Fixture\ProvisionProduct;

#[FactoryProvider(ProvisionProduct::class)]
final readonly class ProvisionProductProvider
{
    public function __invoke(ConfigurationValue $dependency): ProvisionProduct
    {
        return new ProvisionProduct($dependency, $dependency, 'override');
    }
}
