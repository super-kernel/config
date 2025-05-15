<?php
declare (strict_types=1);

namespace SuperKernel\Config;

use Closure;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use RuntimeException;
use SuperKernel\Contract\ConfigProviderInterface;
use SuperKernel\Contract\ProviderConfigInterface;

/**
 * @ProviderConfigFactory
 * @\SuperKernel\Config\ProviderConfigFactory
 */
final class ProviderConfigFactory
{
	private static ?self $instance = null;

	private ?ProviderConfigInterface $providerConfig = null {
		get => $this->providerConfig ??= new class implements ProviderConfigInterface {
			private static ?string       $rootPath        = null;
			private static ?array        $allPackages     = null;
			private static ?array        $providerConfigs = null;
			private readonly ClassLoader $classLoader;

			public function __construct()
			{
				$loaders = ClassLoader::getRegisteredLoaders();

				$this->classLoader = reset($loaders);
			}

			public function getCommands(): array
			{
				return $this->getProviderConfigs()['commands'] ?? [];
			}

			public function getDependencies(): array
			{
				return $this->getProviderConfigs()['dependencies'] ?? [];
			}

			public function getListeners(): array
			{
				return $this->getProviderConfigs()['listeners'] ?? [];
			}

			public function getRootPath(): string
			{
				return self::$rootPath ??= dirname(
					Closure::bind(fn() => $this->vendorDir, $this->classLoader, $this->classLoader)());
			}

			public function getRootPackage(): array
			{
				return InstalledVersions::getRootPackage();
			}

			public function getProviderConfigs(): array
			{
				if (null !== self::$providerConfigs) {
					return self::$providerConfigs;
				}

				$providerConfigs = [];

				foreach ($this->getAllPackages() as $packageName => $package) {
					$configProvider = $package['extra']['SuperKernel']['config'] ?? null;

					if (null === $configProvider) {
						continue;
					}

					if (!is_string($configProvider) ||
					    !class_exists($configProvider) ||
					    !is_a($configProvider, ConfigProviderInterface::class, true)
					) {
						throw new RuntimeException(
							sprintf(
								'The configProvider for package [%s] is invalid, `extra.config` must be an 
								existing classname string that inherits from `ConfigProviderInterface`.',
								$packageName,
							),
						);
					}

					$providerConfigs[] = new $configProvider()();
				}

				return self::$providerConfigs = array_merge_recursive(...$providerConfigs);
			}

			public function getAllPackages(): array
			{
				if (null !== self::$allPackages) {
					return self::$allPackages;
				}

				$versions = InstalledVersions::getAllRawData()[0]['versions'] ?? null;

				if (!$versions) {
					throw new RuntimeException('All raw data are not installed');
				}

				return self::$allPackages = $versions;
			}

			public function __invoke(): ClassLoader
			{
				return $this->classLoader;
			}
		};
	}

	public function __invoke(): ProviderConfigInterface
	{
		return (self::$instance ?? $this)->providerConfig;
	}
}