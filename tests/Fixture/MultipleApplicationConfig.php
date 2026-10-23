<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Fixture;

use Stringable;
use SuperKernel\Attribute\Configuration;
use SuperKernel\Contract\MultipleConfigInterface;

#[Configuration(Stringable::class)]
final class MultipleApplicationConfig implements MultipleConfigInterface
{
    /** @var array<string, int> */
    public array $calls = [];

    #[Configuration(Stringable::class, default: true)]
    public function primary(): Stringable
    {
        $this->calls['primary'] = ($this->calls['primary'] ?? 0) + 1;

        return new ConfigurationValue('primary');
    }

    #[Configuration]
    public function cache(): Stringable
    {
        $this->calls['cache'] = ($this->calls['cache'] ?? 0) + 1;

        return new ConfigurationValue('cache');
    }
}
