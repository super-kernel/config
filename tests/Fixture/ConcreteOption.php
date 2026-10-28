<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Fixture;

final readonly class ConcreteOption
{
    public function __construct(public string $value)
    {
    }
}
