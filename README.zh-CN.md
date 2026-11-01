<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Config

`super-kernel/config` 提供类型化的配置声明、具名条目、默认值选择、延迟加载以及对象身份的保留。它不解析文件、环境变量、命令行参数或数组：原始的外部属性由 `super-kernel/environment` 提供，应用负责构造与组件配置契约兼容的对象，使用方组件负责校验各自领域的值。显式契约可以是类，也可以是接口。

## 单一配置

`SuperKernel\Contract\SingleConfigInterface` 标记一个本身就是单个条目的配置对象。组件的配置接口可以继承它。单一来源也可以省略这个标记。

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

单一来源自动成为默认值，可以通过 `get(FeatureConfigInterface::class)` 和 `get(FeatureConfigInterface::class, 'default')` 两种方式取得。两者都返回原始的源对象。

`Configuration` 省略接口参数时，Config 会推断唯一一个最具体的已实现接口，忽略单一模式标记和继承来的父接口。存在歧义时抛出 `InvalidConfigurationException`。一个契约只能有一个 `Configuration` 来源。

## 多个配置条目

对于同一个契约，可以选择这种来源形式来代替单一来源。实现 `SuperKernel\Contract\MultipleConfigInterface`，并在 `Configuration` 上显式指定条目的类或接口。用 `SuperKernel\Attribute\Configuration` 标记每个条目方法。方法名就是区分大小写的查询键。同一个注解既标记源类，也标记其方法；在方法上省略 `className` 表示使用源的条目契约，也可以显式重复同一个类或接口。方法不能声明独立的配置来源。

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

条目必须是所声明的类或接口的实例，不需要自己的 `Configuration` 标记。条目方法必须是公开、非静态、无参数的，并声明一个与条目契约兼容的非空类或接口返回类型。没有标记的方法不是配置条目。空的条目组、不兼容的签名、重复的条目注解、同时使用两种来源模式标记，或存在多个显式默认值，都会在加载之前的声明校验中失败。

只有一个条目的组会自动把它作为默认值，不需要 `default: true`。有多个条目时，用 `default: true` 选择默认值。如果没有标记任何默认值，具名查询仍然可用；只有不带名称的查询会抛出 `ConfigurationNotFoundException`。默认值从不按方法顺序选取。

```php
$primary = $config->get(FeatureConfigInterface::class);
$same = $config->get(FeatureConfigInterface::class, 'primary');
$cache = $config->get(FeatureConfigInterface::class, 'cache');
```

## 具体的选项类

带有公开属性的 final readonly 类可以作为显式的条目契约。它不需要标记接口或 DI 提供者。用 `#[Configuration(OptionClass::class)]` 声明一个应用来源，实现 `MultipleConfigInterface`，并从一个或多个带注解的方法中返回该选项。命名、默认值、延迟创建、类型校验和身份保留的规则同样适用于类契约和接口契约。

Config 通过容器解析应用来源，并自行调用所选的条目方法。它从不向 DI 请求这些由方法返回的选项。类型化的组件加载器可以校验 `get(OptionClass::class, $name)` 返回的选项，同时保留这个确切的实例。单一来源也可以显式指定一个兼容的类契约。不带 `Configuration` 参数的推断仍然只选择已实现的接口。

## 注册表生命周期

容器提供 `SuperKernel\Contract\ConfigInterface`。创建它时会校验所有 `Configuration` 声明，但不解析来源，也不调用条目方法。

`has(string $interface, ?string $name = null): bool` 从不加载任何东西。带名称时检查该确切条目。不带名称时检查该契约是否有来源声明，即使条目组没有选定默认值。

`get(string $interface, ?string $name = null): ?object` 通过容器解析确切的具体源类，校验源的身份并保留它。对于条目组，它只调用所请求的方法，并只保留一次返回的条目。之后按同一名称或其默认别名查询，返回同一个条目对象。结果是所请求的类或接口的实例；Config 从不把它复制为快照。

未知的契约或显式名称返回 `null`，不会访问容器。已声明的条目组有多个条目但没有默认值时，不带名称的查询会在加载其来源之前抛出异常。失败的来源或方法可以重试；某个方法失败时，已成功的来源仍然被保留。

Config 的异常都实现 `ConfigExceptionInterface`，并原样传播。其他加载失败会变为 `ConfigurationLoadingException`，原始原因保留在其中。递归加载来源或条目会抛出 `CircularConfigurationDependencyException`，其中包含当前的契约/条目路径。对同一个尚未解析的条目的并发访问同样受这一重入检查约束。

## Provision 工厂方法

`#[Provision]` 方法为一个容器服务注册工厂。其所在的类不需要 `Configuration` 声明，类的构造函数和该方法都可以接收注入的依赖：

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

不带名称时，服务标识是声明的非空类或接口返回类型。显式名称会注册这些确切的标识，而不是返回类型。可空、标量、联合、交叉以及未声明的返回类型都需要显式名称。同一个方法上的重复名称是幂等的；不同方法声明同一个标识时，会以 `InvalidConfigurationException` 失败。provision 不能替换它自己所在的源类，因为解析该源类会递归地请求同一个工厂产品。

源必须是可实例化的类。Provision 方法必须是公开、非静态的，并且不能是构造函数或析构函数。声明在构造源之前就会被校验。

已有的 `Provider` 和类级 `FactoryProvider` 映射优先于 provision；provision 优先于普通的类构造。容器保留工厂对象以及每个成功解析的标识。多个名称各自保留独立的产品，与类级工厂提供者的行为一致。重复读取同一个标识会返回其保留的值，对于非类标识也包括 `null`。通过 `ResolverFactoryInterface` 直接调用时，顶层产品由调用方持有，其嵌套依赖仍然共享。

方法参数的默认值、可空回退、歧义处理和引用参数的拒绝，都遵循 DI 的方法注入规则。声明的返回类型由 PHP 强制检查，按类或接口标识请求的结果由容器校验。PSR 容器异常原样传播；其他调用失败会变为 `ConfigurationLoadingException`，原始原因保留在其中。失败的产品可以重试，已成功构造的工厂不会被丢弃。依赖循环是无效的，且不会被检测。

`SuperKernel\Config\Collector\ProvisionCollector` 暴露收集到的 `#[Provision]` 声明，不构造来源，也不调用方法。`getMethods()` 返回一个以声明类和确切方法名为键的数组。每个条目包含 `attribute`（原始的 `Provision` 实例）和 `returnType`（原生的 `ReflectionType`，未声明时为 `null`）。具名、可空、联合和交叉类型保留其原生反射形式；`self`、`parent` 和 `static` 保持为上下文相关的名称。创建收集器时，若类或方法不可用，会抛出 `ReflectionException`。

## 领域校验与 DI

通过一个执行校验的 `FactoryProvider` 绑定组件的默认配置接口。它的实现从 Config 加载默认值，校验领域值，然后返回同一个对象。支持具名条目的组件，可以在默认提供者和连接工厂之间共享一个类型化的配置加载器。这个适配器把发现、名称、默认值和保留交给 Config，并校验每个被选中的条目。

普通的使用方通过类型化注入，接收经过校验的配置接口或具名条目服务。Config 解析声明的具体源类，因此接口提供者不会递归地请求自身。来源和条目方法可以依赖其他配置；依赖循环仍然无效。

运行期间请保持配置 getter 的结果稳定。容器会保留校验成功的默认接口；校验失败时不得发布该接口条目。领域校验失败后，Config 可能已经保留了原始条目，因此之后的尝试必须再次校验。

`Configuration` 声明应用配置。更高优先级的 DI `Provider` 覆盖会绕过被替换的校验器。可选的启用逻辑先用 `has()` 判断声明是否存在，再请求经过校验的接口。没有声明时跳过启用；已声明但来源无效或缺少默认值时，会明确失败。
