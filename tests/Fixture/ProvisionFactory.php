<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Fixture;

use SuperKernel\Attribute\Provision;
use Throwable;

final class ProvisionFactory
{
    public static int $constructions = 0;

    /** @var list<string> */
    public array $calls = [];

    public ?Throwable $failure = null;

    public ?ProvisionProduct $nullableResult = null;

    public function __construct(private readonly ConfigurationValue $dependency)
    {
        ++self::$constructions;
    }

    #[Provision]
    public function product(ConfigurationValue $argument): ProvisionProduct
    {
        $this->calls[] = 'product';

        return new ProvisionProduct($this->dependency, $argument);
    }

    #[Provision('primary', 'secondary', 'primary')]
    public function named(ConfigurationValue $argument, string $label = 'named'): ProvisionProduct
    {
        $this->calls[] = 'named';

        return new ProvisionProduct($this->dependency, $argument, $label);
    }

    /** @throws Throwable When the test supplies a failure */
    #[Provision('unstable')]
    public function unstable(): ProvisionProduct
    {
        $this->calls[] = 'unstable';
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return new ProvisionProduct($this->dependency, $this->dependency);
    }

    #[Provision('nullable')]
    public function nullable(): ?ProvisionProduct
    {
        $this->calls[] = 'nullable';

        return $this->nullableResult;
    }
}
