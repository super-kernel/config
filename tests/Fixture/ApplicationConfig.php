<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Fixture;

use Stringable;
use SuperKernel\Attribute\Configuration;

#[Configuration(Stringable::class)]
final class ApplicationConfig implements Stringable
{
    public string $value = 'configured';
    public int $reads = 0;

    public function __toString(): string
    {
        ++$this->reads;

        return $this->value;
    }
}
