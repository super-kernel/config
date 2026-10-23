<?php

declare(strict_types=1);

namespace SuperKernel\Config;

use Psr\Container\ContainerInterface;
use ReflectionException;
use SuperKernel\Config\Definition\ConfigurationDefinition;
use SuperKernel\Config\Exception\CircularConfigurationDependencyException;
use SuperKernel\Config\Exception\ConfigExceptionInterface;
use SuperKernel\Config\Exception\ConfigurationLoadingException;
use SuperKernel\Config\Exception\ConfigurationNotFoundException;
use SuperKernel\Config\Exception\InvalidConfigurationException;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Contract\ReflectorInterface;
use Throwable;

use function array_key_exists;
use function array_keys;
use function class_exists;
use function get_debug_type;
use function implode;
use function interface_exists;
use function is_string;
use function sprintf;

final class Config implements ConfigInterface
{
    /** @var array<class-string, ConfigurationDefinition> */
    private readonly array $definitions;

    /** @var array<class-string, object> */
    private array $sources = [];

    /** @var array<class-string, true> */
    private array $loadingSources = [];

    /** @var array<class-string, array<string, object>> */
    private array $resolved = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /**
     * @param array<class-string, class-string> $sources
     */
    public function __construct(
        array                               $sources,
        private readonly ContainerInterface $container,
        private readonly ReflectorInterface $reflector,
    )
    {
        $definitions = [];
        foreach ($sources as $interface => $source) {
            if (!is_string($interface) || (!interface_exists($interface) && !class_exists($interface))) {
                throw new InvalidConfigurationException(sprintf(
                    'Configuration registry key %s must name an existing class or interface.',
                    get_debug_type($interface),
                ));
            }

            if (!is_string($source) || !class_exists($source)) {
                throw new InvalidConfigurationException(sprintf(
                    'Configuration source for %s must name a compatible class; %s provided.',
                    $interface,
                    get_debug_type($source),
                ));
            }

            try {
                $reflection = $this->reflector->reflectClass($source);
            } catch (ReflectionException $exception) {
                throw new InvalidConfigurationException(sprintf(
                    'Configuration source %s for %s cannot be reflected.',
                    $source,
                    $interface,
                ), 0, $exception);
            }

            $definitions[$interface] = new ConfigurationDefinition($interface, $reflection);
        }

        $this->definitions = $definitions;
    }

    public function has(string $interface, ?string $name = null): bool
    {
        $definition = $this->definitions[$interface] ?? null;

        return null !== $definition && (null === $name || array_key_exists($name, $definition->items));
    }

    /** @throws ConfigExceptionInterface When source or item loading and type validation fail */
    public function get(string $interface, ?string $name = null): ?object
    {
        $definition = $this->definitions[$interface] ?? null;
        if (null === $definition) {
            return null;
        }

        $name ??= $definition->default ?? throw new ConfigurationNotFoundException(sprintf(
            'Configuration contract %s has multiple items but no default item is declared.',
            $interface,
        ));
        if (!array_key_exists($name, $definition->items)) {
            return null;
        }
        if (isset($this->resolved[$interface][$name])) {
            return $this->resolved[$interface][$name];
        }

        $method = $definition->items[$name];
        $key = null === $method ? $interface : $interface . '::' . $name;
        if (isset($this->resolving[$key]) || isset($this->loadingSources[$interface])) {
            throw new CircularConfigurationDependencyException(sprintf(
                'Circular configuration dependency detected: %s.',
                implode(' -> ', [
                    ...array_keys($this->resolving),
                    $key,
                ]),
            ));
        }

        $this->resolving[$key] = true;
        try {
            $source = $this->source($interface, $definition->source);
            $configuration = null === $method ? $source : $source->{$method}();
            if (!$configuration instanceof $interface) {
                throw new ConfigurationLoadingException(sprintf(
                    'Configuration item %s resolved to %s instead of %s.',
                    $key,
                    get_debug_type($configuration),
                    $interface,
                ));
            }

            return $this->resolved[$interface][$name] = $configuration;
        } catch (ConfigExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            $configException = self::findPreviousConfigException($throwable);
            if (null !== $configException) {
                throw $configException;
            }

            throw new ConfigurationLoadingException(sprintf(
                'Unable to load configuration item %s from source %s.',
                $key,
                $definition->source,
            ), 0, $throwable);
        } finally {
            unset($this->resolving[$key]);
        }
    }

    /**
     * @param class-string $interface
     * @param class-string $source
     *
     * @throws Throwable When source resolution or type validation fails
     */
    private function source(string $interface, string $source): object
    {
        if (isset($this->sources[$interface])) {
            return $this->sources[$interface];
        }

        $this->loadingSources[$interface] = true;
        try {
            $configuration = $this->container->get($source);
            if (!$configuration instanceof $source) {
                throw new ConfigurationLoadingException(sprintf(
                    'Configuration source %s for %s resolved to %s.',
                    $source,
                    $interface,
                    get_debug_type($configuration),
                ));
            }

            return $this->sources[$interface] = $configuration;
        } finally {
            unset($this->loadingSources[$interface]);
        }
    }

    private static function findPreviousConfigException(Throwable $throwable): ?ConfigExceptionInterface
    {
        $cause = $throwable->getPrevious();

        while (null !== $cause) {
            if ($cause instanceof ConfigExceptionInterface) {
                return $cause;
            }

            $cause = $cause->getPrevious();
        }

        return null;
    }
}
