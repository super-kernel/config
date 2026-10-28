<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Fixture\Provider;

use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Stringable;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Config\Exception\ConfigurationNotFoundException;
use SuperKernel\Contract\ConfigInterface;

#[FactoryProvider(Stringable::class)]
final readonly class ValidatedConfigProvider
{
    /** @throws ContainerExceptionInterface */
    public function __invoke(ConfigInterface $config): Stringable
    {
        $source = $config->get(Stringable::class)
            ?? throw new ConfigurationNotFoundException('No Stringable configuration source is declared.');
        if ('' === (string) $source) {
            throw new InvalidArgumentException('The configuration value must not be empty.');
        }

        return $source;
    }
}
