<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Fixture;

use SuperKernel\Attribute\Configuration;
use SuperKernel\Contract\MultipleConfigInterface;

#[Configuration(ConcreteOption::class)]
final class ConcreteConfigurationItems implements MultipleConfigInterface
{
    /** @var array<string, int> */
    public array $calls = [];

    #[Configuration(default: true)]
    public function primary(): ConcreteOption
    {
        $this->calls['primary'] = ($this->calls['primary'] ?? 0) + 1;

        return new ConcreteOption('primary');
    }

    #[Configuration]
    public function cache(): ConcreteOption
    {
        $this->calls['cache'] = ($this->calls['cache'] ?? 0) + 1;

        return new ConcreteOption('cache');
    }
}
