<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Fixture;

use Stringable;
use SuperKernel\Attribute\Autowired;

final class ConfigConsumer
{
    #[Autowired]
    public Stringable $injected;

    public function __construct(public readonly Stringable $config)
    {
    }
}
