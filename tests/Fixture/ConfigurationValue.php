<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Fixture;

use Stringable;
use SuperKernel\Contract\SingleConfigInterface;

final readonly class ConfigurationValue implements SingleConfigInterface, Stringable
{
    public function __construct(private string $value = 'configured')
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
