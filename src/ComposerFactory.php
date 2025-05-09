<?php
declare(strict_types=1);

namespace SuperKernel\Config;

use Composer\Autoload\ClassLoader;
use Phar;
use RuntimeException;
use SuperKernel\Contract\ComposerInterface;

/**
 * @ComposerFactory
 * @\SuperKernel\Di\Composer\ComposerFactory
 */
final class ComposerFactory
{
	private static ?ComposerFactory $instance = null;

	private ?ComposerInterface $composer = null {
		get => $this->composer ??= new class implements ComposerInterface {
			private ?ClassLoader $classLoader = null {
				get => $this->classLoader ??= (function () {
					$classLoader = ClassLoader::getRegisteredLoaders();
					return $this->classLoader = reset($classLoader);
				})();
			}

			private ?string $vendorDir = null {
				get => $this->vendorDir ??= (fn() => $this->vendorDir)->call($this->classLoader);
			}

			private ?string $rootPath = null {
				get => $this->rootPath ??= dirname(Phar::running(false) ?: $this->vendorDir);
			}


			private ?array $configProviders = null {
				get => $this->configProviders ??= (function () {
					$providerConfigs = [];

					foreach ($this->getMergedExtra('super-kernel')['config'] ?? [] as $configProvider) {
						if (is_string($configProvider) && class_exists($configProvider)) {
							$providerConfigs[] = new $configProvider()();
						}
					}

					return array_merge_recursive(...$providerConfigs);
				})();
			}

			private array $extra;
			private array $script;
			private array $versions;

			private array $installedContent;

			public function __construct()
			{
				$path = $this->vendorDir . '/composer/installed.json';

				if (!file_exists($path)) {
					throw new RuntimeException('install.json file does not exist !');
				}
				if (!is_readable($path)) {
					throw new RuntimeException('install.json file is not readable !');
				}

				$installedContent = file_get_contents($path);

				if (!json_validate($installedContent)) {
					throw new RuntimeException('State mismatch (invalid or malformed JSON).');
				}

				$installedJsonContent = json_decode($installedContent, true);

				$packages    = $installedJsonContent['packages'] ?? [];
				$devPackages = $installedJsonContent['packages-dev'] ?? [];

				foreach ($packages + $devPackages as $package) {
					if (!$packageName = $package['name']) {
						continue;
					}

					foreach ($package as $key => $value) {
						match ($key) {
							'extra'   => $this->extra[$packageName] = $value,
							'script'  => $this->script[$packageName] = $value,
							'version' => $this->versions[$packageName] = $value,
							default   => null,
						};
					}
				}

				$this->installedContent = $installedJsonContent;
			}

			/**
			 * @return string
			 */
			public function getRootPath(): string
			{
				return $this->rootPath;
			}

			/**
			 * @return string
			 */
			public function getVendorDir(): string
			{
				return $this->vendorDir;
			}

			public function getClassLoader(): ClassLoader
			{
				return $this->classLoader;
			}

			/**
			 * @param string|null $key
			 *
			 * @return array
			 */
			public function getMergedExtra(?string $key = null): array
			{
				if (null === $key) {
					return $this->extra;
				}

				$extra = [];

				foreach ($this->extra as $config) {
					if (!$configs = $config[$key] ?? null) {
						continue;
					}

					foreach ($configs as $k => $v) {
						$extra[$k] = array_merge($extra[$k] ?? [], is_array($v) ? $v : [$v]);
					}
				}

				return $extra;
			}

			/**
			 * @return array
			 */
			public function getDependencies(): array
			{
				return $this->configProviders['dependencies'] ?? [];
			}

			public function InstalledContent(): array
			{
				return $this->installedContent;
			}

			public function getListeners(): array
			{
				return $this->configProviders['listeners'] ?? [];
			}
		};
	}

	public function __invoke(): ComposerInterface
	{
		return (self::$instance ??= $this)->composer;
	}
}