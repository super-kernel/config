# SuperKernel Config

`super-kernel/config` owns typed configuration declarations, named items, default selection, lazy loading, and
retained object identity. It does not parse files, environment variables, command-line arguments, or arrays.
Environment owns raw external properties; applications construct objects compatible with component configuration
contracts, and consuming components validate their domain values. An explicit contract can be a class or interface.

## Single configuration

`SuperKernel\Contract\SingleConfigInterface` marks a configuration object that is itself one item. Component
configuration interfaces may extend it. Single sources may omit this marker.

```php
use SuperKernel\Attribute\Configuration;
use SuperKernel\Contract\SingleConfigInterface;

interface FeatureConfigInterface extends SingleConfigInterface
{
    public function value(): string;
}

#[Configuration(FeatureConfigInterface::class)]
final readonly class FeatureConfig implements FeatureConfigInterface
{
    public function value(): string
    {
        return 'primary';
    }
}
```

A single source is automatically the default and is available through both `get(FeatureConfigInterface::class)`
and `get(FeatureConfigInterface::class, 'default')`. Both return the original source object.

When `Configuration` omits its interface argument, Config infers the one most-specific implemented interface,
ignoring the single-mode marker and inherited parent interfaces. Ambiguity raises `InvalidConfigurationException`.
A contract may have only one `Configuration` source.

## Multiple configuration items

Choose this source form instead of the single source for the same contract. Implement
`SuperKernel\Contract\MultipleConfigInterface` and explicitly identify the item class or interface on `Configuration`.
Mark each item method with `SuperKernel\Attribute\Configuration`. The method name is its case-sensitive lookup key.
The same Attribute marks the source class and its methods; on methods, omit `className` to use the source's item
contract, or repeat that exact class or interface explicitly. Methods do not declare independent configuration sources.

```php
use SuperKernel\Attribute\Configuration;
use SuperKernel\Contract\MultipleConfigInterface;

// FeatureSettings is an application-owned implementation of FeatureConfigInterface.
#[Configuration(FeatureConfigInterface::class)]
final readonly class FeatureConfigurations implements MultipleConfigInterface
{
    #[Configuration(default: true)]
    public function primary(): FeatureConfigInterface
    {
        return new FeatureSettings('primary');
    }

    #[Configuration]
    public function cache(): FeatureConfigInterface
    {
        return new FeatureSettings('cache');
    }
}
```

Items must be instances of the declared class or interface and do not need their own `Configuration` marker. Item methods must be public,
non-static, parameterless, and declare a non-nullable class or interface return type compatible with the item
contract. Unmarked methods are not configuration items. An empty group, incompatible signature, repeated item
Attribute, both source-mode markers, or multiple explicit defaults fails declaration validation before loading.

A group containing one item automatically uses that item as its default; no `default: true` is needed.
With several items, `default: true` selects the default. If none is marked, named lookups remain usable;
only an unnamed lookup throws `ConfigurationNotFoundException`. Defaults are never chosen by method ordering.

```php
$primary = $config->get(FeatureConfigInterface::class);
$same = $config->get(FeatureConfigInterface::class, 'primary');
$cache = $config->get(FeatureConfigInterface::class, 'cache');
```

## Concrete option classes

A final readonly class with public properties can be an explicit item contract. It does not need a marker interface
or a DI provider. Declare an application source with `#[Configuration(OptionClass::class)]`, implement
`MultipleConfigInterface`, and return the option from one or more attributed methods. The same naming, default,
lazy creation, type validation, and identity-retention rules apply to class and interface contracts.

Config resolves the application source through the container and invokes the selected item method itself.
It never requests those method-returned options from DI. A typed component loader can validate an option returned
by `get(OptionClass::class, $name)` while retaining that exact instance. Single sources may also explicitly name a
compatible class contract. Inference without a `Configuration` argument continues to select an implemented interface.

## Provision method collection

`SuperKernel\Config\Collector\ProvisionCollector` receives `AnnotationCollectorInterface` and `ReflectorInterface`
through constructor injection. Construction queries exact `SuperKernel\Attribute\Provision` method metadata through
the injected collector's `getMethodsWithAttribute()` and reflects each marked method immediately, without constructing sources
or invoking their methods. A class-level `Configuration` declaration is not required.

`getMethods()` returns an array keyed by declaring class and exact method name. Each entry contains `attribute`,
the original trusted `Provision` instance, and `returnType`, the native `ReflectionType` or `null` when undeclared.
Named, nullable, union, and intersection types retain their native reflection form; `self`, `parent`, and `static`
remain contextual names. Attribute names retain their order and duplicates. Repeated reads reuse the collected
metadata. An unavailable class or method raises `ReflectionException` during collector construction.

The collector exposes declaration metadata independently of factory registration. It accepts every native return
type; factory applicability and service identifiers are validated by the definer described below.

## Provision factory providers

`Definer\ProvisionDefiner` is registered with `#[Definer(priority: 50)]` and produces `Definition\ProvisionDefinition`
entries. `Resolver\ProvisionResolver`, registered with `#[Resolver(priority: 400)]`, obtains the factory object from
the root container, resolves method arguments through DI's existing `MethodDefinition` dispatcher, and invokes the
selected method. The source constructor and provision method can both receive injected dependencies.

```php
use SuperKernel\Attribute\Provision;

final readonly class ApplicationServices
{
    public function __construct(private ClockSettings $settings)
    {
    }

    #[Provision]
    public function clock(): ClockInterface
    {
        return new SystemClock($this->settings->timezone());
    }

    #[Provision('primary-clock', 'secondary-clock')]
    public function namedClock(): ClockInterface
    {
        return new SystemClock($this->settings->timezone());
    }
}
```

With no names, the service identifier is the declared non-nullable class or interface return type. Explicit names
register exactly those identifiers instead of the return type. Nullable, scalar, union, intersection, and undeclared
return types require explicit names. Repeated names on the same method are idempotent; different methods claiming
one identifier fail with `InvalidConfigurationException`. A provision cannot replace its own source class because
resolving that source would recursively request the same factory product.

Sources must be instantiable classes. Provision methods must be public, non-static, and must not be constructors
or destructors. Declarations are validated before source construction. During DI bootstrap the definer consumes
`AnnotationCollectorInterface` directly and uses native reflection, since ordinary services cannot yet be resolved.
It preserves Annotator's first-owner behavior when a class appears in more than one package.

Both `ProvisionCollector` and `ProvisionDefiner` call the shared collector method directly.
Matching and class ownership belong to the collector; neither consumer traverses package records. The definer does not resolve the runtime collector or reflector during
bootstrap. Return-type inspection remains in the collector, while factory validation and service-name interpretation
remain in the definer.

Existing `Provider` and class-level `FactoryProvider` mappings take priority over provisions; provisions take
priority over ordinary class construction. DI retains the factory object and each successfully resolved identifier.
Multiple names have independent retained products, matching class-level factory-provider behavior. Repeated reads
of the same identifier return its retained value, including `null` for a non-class identifier. Direct calls through
`ResolverFactoryInterface` leave the top-level product caller-owned while sharing its nested dependencies.

Method parameter defaults, nullable fallbacks, ambiguity, and reference rejection follow DI's existing method
resolver. PHP enforces declared return types, and the container verifies results requested by class or interface ID.
PSR container exceptions propagate unchanged; other invocation failures become `ConfigurationLoadingException`
with the original cause. Failed products are retried without discarding a successfully constructed factory.
Dependency cycles remain invalid and are not detected by this extension.

Provision execution requires `super-kernel/di` as a production
dependency of Config; DI does not depend on Config. Complete application annotation scans discover both handlers.

## Registry lifecycle

`Provider\ConfigProvider` provides `SuperKernel\Contract\ConfigInterface`. It discovers exact `Configuration`
metadata through Annotator, validates the complete declaration map with Reflector, and constructs the registry
without resolving sources or invoking item methods. Class-level metadata remains Annotator-owned; Config inspects
item methods and constructs their native method Attributes during declaration validation.

`has(string $interface, ?string $name = null): bool` never loads anything. With a name it checks that exact item.
Without a name it checks whether the contract has a source declaration, even if a group has no default selected.

`get(string $interface, ?string $name = null): ?object` resolves the exact concrete source class through the injected
container, verifies source identity, and retains it. For a group it invokes only the requested method and retains
the returned item once. A later lookup by the same name or its default alias returns the same item object.
The result is an instance of the requested class or interface; Config never copies it into a snapshot.

Unknown contracts or explicit names return `null` without consulting the container. A declared group with several
items but no default throws on an unnamed lookup before loading its source. A failed source or method can be
retried; a successful source remains retained when one of its methods fails.

Config-owned exceptions propagate unchanged. Foreign loading failures become `ConfigurationLoadingException`
with their original cause. Recursive source or item loading raises `CircularConfigurationDependencyException`
with the active contract/item path. Independent item markers are cleared individually, including when loads finish
out of order. Concurrent access to the same unresolved item remains subject to this re-entry check.

## Domain validation and DI

Bind a component's default configuration interface through a validating `FactoryProvider`. Its implementation
loads the default from Config, validates domain values, and returns that same object. A component that supports
named items may share a typed configuration loader between its default provider and connection factories.
This adapter delegates discovery, names, defaults, and retention to Config and validates each selected item.

Ordinary consumers receive the validated configuration interface or named-item service through typed injection.
Config resolves the declared concrete source class, so an interface provider does not recursively request itself.
Sources and item methods may depend on other configurations; dependency cycles remain invalid.

Keep configuration getters stable during runtime. DI retains successfully validated default interfaces; failed
validation must not publish an interface entry. Config can already retain the raw item after domain validation
fails, so later attempts must validate it again.

`Configuration` declares application configuration. A higher-priority DI `Provider` override bypasses the replaced
validator. Optional activation uses `has()` for declaration presence and then requests the validated interface.
A missing declaration skips activation; a declared invalid source or missing default fails explicitly.

## Development

Use a PHP 8.4 Composer in this package's checkout:

```bash
composer install
composer validate --strict
composer test
composer format:check
composer format
```
