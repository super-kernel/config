<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Config

`super-kernel/config` 为应用提供类型化的配置：类用 `#[Configuration]` 声明配置对象，代码按其类或接口读取它们，可以取默认项也可以按名称读取；配置在第一次使用时加载，之后保留。它还把 `#[Provision]` 方法变成容器服务。

## 安装

```bash
composer require super-kernel/config
```

本包会安装 `super-kernel/di`，由它的容器创建配置来源；本包还需要 `skernel build` 的元数据，由构建收集这些声明。

## 提供的能力

- 用 `#[Configuration]` 声明的配置来源：单个对象，或者由其方法提供具名配置项并带有默认项的来源。
- 用 `ConfigInterface::get()` 和 `has()` 按类或接口以及可选的名称读取配置项。
- 配置项在第一次使用时创建，之后返回同一个对象，以默认名称获取时也是如此。
- 在加载任何内容之前检查所有声明，并检测循环的配置依赖。
- 由 `#[Provision]` 方法创建的容器服务，以其返回类型或给定的名称注册。

它不会：

- 读取文件、环境变量、命令行参数或数组；原始属性由 `super-kernel/environment` 读取，对象由你的配置来源构建；
- 检查组件所需的值，例如端口范围；由组件自己检查；
- 把配置项复制为快照：你得到的就是你的配置来源返回的对象。

## 快速开始

声明一个配置接口。将它保存为 `src/Mail/MailConfig.php`：

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

为它声明应用的配置来源。将它保存为 `src/Mail/AppMailConfig.php`：

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

读取它。将脚本保存为 `bin/config.php`：

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

构建项目并运行脚本：

```bash
skernel build
php bin/config.php
```

它会打印：

```text
smtp.example.com:587
bool(true)
```

## 公开接口

| 名称 | 类别 | 用途 |
| --- | --- | --- |
| `SuperKernel\Attribute\Configuration` | 注解 | 在类上声明配置来源，或在方法上声明一个具名配置项。 |
| `SuperKernel\Contract\SingleConfigInterface` | 接口 | 标记一个自身就是默认项的配置对象。 |
| `SuperKernel\Contract\MultipleConfigInterface` | 接口 | 标记一个由其 `#[Configuration]` 方法提供具名配置项的来源。 |
| `SuperKernel\Contract\ConfigInterface` | 接口 | `get()` 加载配置项，`has()` 检查声明而不加载。 |
| `SuperKernel\Attribute\Provision` | 注解 | 声明一个创建容器服务的方法。 |
| `SuperKernel\Config\Collector\ProvisionCollector` | 类 | 列出 `#[Provision]` 方法及其返回类型，但不调用它们。 |

## 需要了解的行为

- 每个声明都在第一次创建 `ConfigInterface` 时检查。无效的声明会抛出 `SuperKernel\Config\Exception\InvalidConfigurationException`，例如 `Configuration contract Src\Mail\MailConfig is declared by both Src\Mail\AppMailConfig and Src\Mail\OtherMailConfig.`，并且 `ConfigInterface` 无法被创建。
- 对于未声明的类、接口或名称，`get()` 返回 `null`。对于有多个配置项但没有默认项的来源，不带名称调用 `get()` 会抛出 `SuperKernel\Config\Exception\ConfigurationNotFoundException`；按名称查找仍然可用。
- 直接或间接需要自己正在提供的配置项的来源会抛出 `SuperKernel\Config\Exception\CircularConfigurationDependencyException`，例如 `Circular configuration dependency detected: Src\A -> Src\B -> Src\A.`。
- 两个 `#[Provision]` 方法声明同一个服务名称时，容器将无法解析任何尚未创建的条目。
- 本包的所有异常都是 PSR-11 容器异常。添加或修改声明之后，请重新运行 `skernel build`，或者用会先构建的 `skernel run` 启动。

## 文档

开发者文档用完整示例介绍配置来源、具名配置项、默认项、加载、组件中的校验、工厂方法以及各种错误，并说明不要这样做的事情：[SuperKernel Config](https://github.com/super-kernel/.github/blob/main/docs/components/config.zh-CN.md)。

## 许可证

基于 MIT 许可证发布，详见 [LICENSE](LICENSE)。
