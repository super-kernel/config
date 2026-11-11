<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Config

`super-kernel/config` gives an application typed configuration: classes declare configuration objects with `#[Configuration]`, and code reads them by their class or interface, by default or by name, loaded when first used and kept afterwards. It also turns `#[Provision]` methods into container services.

## Installation

```bash
composer require super-kernel/config
```

The package installs `super-kernel/di`, whose container creates the configuration sources, and needs the metadata of `skernel build`, which collects the declarations.

## What it provides

- Configuration sources declared with `#[Configuration]`: a single object, or a source whose methods provide named items with a default.
- `ConfigInterface::get()` and `has()` to read an item by its class or interface and an optional name.
- Items created on first use and the same object returned afterwards, also under the default name.
- Checks of every declaration before anything is loaded, and detection of circular configuration dependencies.
- Container services created by `#[Provision]` methods, under their return type or the given names.

It does not:

- read files, environment variables, command-line arguments, or arrays; `super-kernel/environment` reads raw properties, and your sources build the objects;
- check the values a component needs, such as a port range; the component checks them;
- copy items into snapshots: you receive the objects your sources return.

## Quick start

Declare a configuration interface. Save it as `src/Mail/MailConfig.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Mail;

use SuperKernel\Contract\SingleConfigInterface;

interface MailConfig extends SingleConfigInterface
{
    public function host(): string;

    public function port(): int;
}
```

Declare the application's source for it. Save it as `src/Mail/AppMailConfig.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Mail;

use SuperKernel\Attribute\Configuration;

#[Configuration(MailConfig::class)]
final readonly class AppMailConfig implements MailConfig
{
    public function host(): string
    {
        return 'smtp.example.com';
    }

    public function port(): int
    {
        return 587;
    }
}
```

Read it. Save the script as `bin/config.php`:

```php
<?php

declare(strict_types=1);

use Src\Mail\MailConfig;
use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\ConfigInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = ApplicationContext::getContainer()->get(ConfigInterface::class);
$mail = $config->get(MailConfig::class);

echo $mail->host(), ':', $mail->port(), PHP_EOL;
var_dump($mail === $config->get(MailConfig::class, 'default'));
```

Build the project and run the script:

```bash
skernel build
php bin/config.php
```

It prints:

```text
smtp.example.com:587
bool(true)
```

## Public surface

| Name | Kind | Purpose |
| --- | --- | --- |
| `SuperKernel\Attribute\Configuration` | Attribute | Declares a configuration source on a class, or a named item on a method. |
| `SuperKernel\Contract\SingleConfigInterface` | Interface | Marks a configuration object that is itself the default item. |
| `SuperKernel\Contract\MultipleConfigInterface` | Interface | Marks a source whose `#[Configuration]` methods provide named items. |
| `SuperKernel\Contract\ConfigInterface` | Interface | `get()` loads an item, `has()` checks a declaration without loading. |
| `SuperKernel\Attribute\Provision` | Attribute | Declares a method that creates a container service. |
| `SuperKernel\Config\Collector\ProvisionCollector` | Class | Lists the `#[Provision]` methods and their return types without calling them. |

## Behavior to know

- Every declaration is checked when `ConfigInterface` is first created. An invalid one raises `SuperKernel\Config\Exception\InvalidConfigurationException`, such as `Configuration contract Src\Mail\MailConfig is declared by both Src\Mail\AppMailConfig and Src\Mail\OtherMailConfig.`, and `ConfigInterface` cannot be created.
- `get()` returns `null` for an undeclared class, interface, or name. For a source with several items and no default, `get()` without a name raises `SuperKernel\Config\Exception\ConfigurationNotFoundException`; named lookups still work.
- A source that needs, directly or indirectly, an item it is itself providing raises `SuperKernel\Config\Exception\CircularConfigurationDependencyException`, such as `Circular configuration dependency detected: Src\A -> Src\B -> Src\A.`
- Two `#[Provision]` methods for one service name make the container unable to resolve any entry it has not created yet.
- All exceptions of the package are PSR-11 container exceptions. Run `skernel build` again after adding or changing a declaration, or start with `skernel run`, which builds first.

## Documentation

The developer documentation describes sources, named items, defaults, loading, validation in components, provisions, and the errors with complete examples and what not to do: [SuperKernel Config](https://github.com/super-kernel/.github/blob/main/docs/components/config.md).

## License

Released under the MIT License. See [LICENSE](LICENSE).
