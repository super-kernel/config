<?php

declare(strict_types=1);

namespace SuperKernel\Config\Definition;

use ReflectionClass;
use ReflectionNamedType;
use SuperKernel\Attribute\Configuration;
use SuperKernel\Config\Exception\InvalidConfigurationException;
use SuperKernel\Contract\MultipleConfigInterface;
use SuperKernel\Contract\SingleConfigInterface;
use Throwable;

use function array_key_first;
use function count;
use function is_a;
use function sprintf;

/** @internal Validates one source declaration without constructing its source or invoking item methods. */
final readonly class ConfigurationDefinition
{
    /** @var class-string */
    public string $source;

    /** @var array<string, string|null> Item names mapped to methods, or null for a single source */
    public array $items;

    public ?string $default;

    /**
     * @param class-string            $interface
     * @param ReflectionClass<object> $implementation
     *
     * @throws InvalidConfigurationException When the source or its item declarations violate the contract
     */
    public function __construct(string $interface, ReflectionClass $implementation)
    {
        $this->source = $implementation->getName();
        $multiple = $implementation->implementsInterface(MultipleConfigInterface::class);

        if (!$implementation->isInstantiable() || (!$multiple && !is_a($this->source, $interface, true))) {
            throw new InvalidConfigurationException(sprintf(
                'Configuration source %s must be an instantiable class compatible with %s or implementing %s.',
                $this->source,
                $interface,
                MultipleConfigInterface::class,
            ));
        }

        if ($multiple && $implementation->implementsInterface(SingleConfigInterface::class)) {
            throw new InvalidConfigurationException('A configuration source cannot be both single and multiple.');
        }

        if (!$multiple) {
            $this->items = [
                'default' => null,
            ];
            $this->default = 'default';

            return;
        }

        $items = [];
        $default = null;
        foreach ($implementation->getMethods() as $method) {
            $attributes = $method->getAttributes(Configuration::class);
            if ([] === $attributes) {
                continue;
            }

            $name = $method->getName();
            $type = $method->getReturnType();
            if (
                1 !== count($attributes)
                || !$method->isPublic()
                || $method->isStatic()
                || 0 !== $method->getNumberOfParameters()
                || !$type instanceof ReflectionNamedType
                || $type->isBuiltin()
                || $type->allowsNull()
                || !is_a($type->getName(), $interface, true)
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'Configuration item %s::%s must be a public non-static parameterless method returning %s, '
                    . 'marked exactly once with Configuration.',
                    $this->source,
                    $name,
                    $interface,
                ));
            }

            try {
                $attribute = $attributes[0]->newInstance();
            } catch (Throwable $exception) {
                throw new InvalidConfigurationException(sprintf(
                    'Unable to construct configuration item metadata for %s::%s.',
                    $this->source,
                    $name,
                ), 0, $exception);
            }

            if (
                null !== $attribute->className
                && $interface !== $attribute->className
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'Configuration item %s::%s must use its source contract %s instead of %s.',
                    $this->source,
                    $name,
                    $interface,
                    $attribute->className,
                ));
            }

            $items[$name] = $name;
            if ($attribute->default) {
                if (null !== $default) {
                    throw new InvalidConfigurationException(sprintf(
                        'Multiple configuration source %s declares more than one default item.',
                        $this->source,
                    ));
                }
                $default = $name;
            }
        }

        if ([] === $items) {
            throw new InvalidConfigurationException(sprintf(
                'Multiple configuration source %s must declare at least one configuration item.',
                $this->source,
            ));
        }

        $this->items = $items;
        $this->default = $default ?? (1 === count($items) ? array_key_first($items) : null);
    }
}
