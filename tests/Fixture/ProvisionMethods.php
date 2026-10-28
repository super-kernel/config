<?php

declare(strict_types=1);

namespace SuperKernel\Config\Tests\Fixture;

use Countable;
use Iterator;
use Stringable;
use SuperKernel\Attribute\Configuration;
use SuperKernel\Attribute\Provision;

/** Reflection-only declarations exercise return types without constructing a source or invoking its methods. */
abstract class ProvisionMethods
{
    #[Configuration]
    #[Provision('primary', 'alias', 'primary')]
    abstract public function Primary(): Stringable;

    #[Provision]
    abstract public function nullable(): ?Stringable;

    #[Provision]
    abstract public function union(): Stringable|Countable;

    #[Provision]
    abstract public function intersection(): Stringable&Countable;

    #[Provision]
    abstract public function disjunctive(): (Stringable&Countable)|Iterator;

    #[Provision]
    abstract public function contextual(): self;

    #[Provision]
    abstract public function lateStatic(): static;

    #[Provision]
    abstract public function builtin(): string;

    /** Intentionally omits a return type to exercise absent declaration metadata. */
    #[Provision]
    abstract public function untyped();

    #[Configuration]
    abstract public function unrelated(): void;

    abstract public function unmarked(): void;
}
