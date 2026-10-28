<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use Composer\InstalledVersions;
use DressCode\{ConfigurationException, Plugin, Profile};
use DressCode\Engine\Helpers;
use function array_key_exists, is_array, is_string;


/**
 * What the installed packages bring to a project without being named in its configuration: the upgrading files under
 * the directory `extra.dresscode.upgrading-dir` names, read for the versions of their package the code must work with
 * (`UpgradingFile::selectFor()`), with those of PHP for its target (`readPhpData()`), and the plugin under
 * `extra.dresscode.plugin`, which the project lets in by naming the package or the plugin in `use`, as Composer lets in
 * its own plugins. The root package takes part too, and a file about the root itself applies whole, and so do the
 * packages of upgrading data installed beside DressCode, which `upgrade` installs for a project lacking them. Such a
 * file never turns a rule on itself; its maps, and the requirements of the rules of the plugin of its package, are
 * laid under those of the project where the project says `upgrading.packages: yes`, those of PHP where it says
 * `upgrading.php.deprecatedApi: forbidden`.
 * @internal
 */
final readonly class PackageDiscovery
{
	/** the keys of `extra.dresscode` a package may use */
	public const Keys = ['upgrading-dir', 'packages', 'plugin'];

	/** the type of the packages that bring DressCode upgrading data or a plugin */
	public const PackageType = 'dresscode-plugin';

	/** the platform package of Composer the upgrading data of PHP are about, which DressCode ships itself */
	public const Php = 'php';

	/** the upgrading data of PHP */
	public const PhpFile = __DIR__ . '/../Rules/Upgrading/php.neon';

	/** the requirement that lays the upgrading data of PHP, under which what they say is reported */
	public const PhpDecision = 'upgrading.php.deprecatedApi';

	/**
	 * What packages declare in their namespaces for the files a project writes into them, which a package does not say
	 * itself yet: the container configurator of Symfony, whose functions the files that configure the services call
	 * without importing them.
	 */
	private const Known = [
		'symfony/dependency-injection' => [
			'functions' => [
				'Symfony\Component\DependencyInjection\Loader\Configurator\{abstract_arg, closure, env, expr, inline_service, iterator, lazy_proxy, param, service, service_closure, service_locator, tagged_class_map, tagged_iterator, tagged_locator}',
			],
		],
	];


	private function __construct(
		/** @var list<UpgradingData>  the root package first */
		public array $upgradingData,
		/** @var array<string, class-string<Plugin>>  package => the plugin it brings, which loads where `use` names one of them */
		public array $plugins,
		/** @var list<string> */
		public array $warnings,
	) {
	}


	/**
	 * @param  ?array<string, string>  $installation  the packages of upgrading data installed beside DressCode => where
	 *   they lie, by default those of the running process
	 * @throws ConfigurationException  for a file a package ships that cannot be read
	 */
	public static function discover(ProjectPackages $project, ?array $installation = null): self
	{
		// the root package first, then the installed ones as Composer lists them, then the data beside DressCode
		$packages = $project->rootPath === null
			? []
			: [[$project->rootName ?? 'the root package', $project->rootPath, $project->rootExtra['dresscode'] ?? null]];
		foreach ($project->installed as $name => $package) {
			$packages[] = [$name, $package['path'], $package['extra']['dresscode'] ?? null];
		}

		foreach ($installation ?? self::findInstalledDataPackages() as $name => $path) {
			if (!$project->has($name)) {
				$packages[] = [$name, $path, ComposerJson::read("$path/composer.json")['extra']['dresscode'] ?? null];
			}
		}

		$upgradingData = $plugins = $warnings = [];
		foreach ($packages as [$name, $path, $extra]) {
			if (!is_array($extra)) {
				continue;
			}

			// a package may be newer than this DressCode and say what it does not know yet
			foreach (array_diff(array_keys($extra), self::Keys) as $key) {
				$warnings[] = "Package `$name`: `extra.dresscode` in its `composer.json` holds the key `$key`, which this DressCode does not know; skipped.";
			}

			if (isset($extra['upgrading-dir']) && $path === null) {
				$warnings[] = "Package `$name` ships upgrading files, but Composer says nothing about where it is installed; skipped.";
			}

			foreach ($path === null ? [] : self::findUpgradingFiles($path, $extra, $name) as $file) {
				$data = self::readFile("$path/$file", new Layer(LayerKind::Package, $file, $name), $project);
				if ($data !== null) {
					$upgradingData[] = $data;
				}
			}

			$plugin = $extra['plugin'] ?? null;
			if (is_string($plugin)) {
				if (is_subclass_of($plugin, Plugin::class)) {
					$plugins[$name] = $plugin;
				} elseif (!class_exists($plugin)) {
					$warnings[] = "Package `$name` names the plugin `$plugin`, which the autoloader does not find; skipped.";
				} else {
					$warnings[] = "Package `$name` names the plugin `$plugin`, which is not a plugin; skipped.";
				}
			}
		}

		foreach (self::Known as $package => $namespaces) {
			if ($project->has($package)) {
				$upgradingData[] = new UpgradingData(
					new Layer(LayerKind::Package, package: $package),
					$package,
					new Profile(namespaces: $namespaces)->namespaces,
					[],
				);
			}
		}

		$php = self::readPhpData($project);
		return new self($php === null ? $upgradingData : [...$upgradingData, $php], $plugins, $warnings);
	}


	/** The upgrading data of PHP cut to the versions of PHP the project says it is written for; null where it says none. */
	public static function readPhpData(ProjectPackages $project): ?UpgradingData
	{
		return self::readFile(
			self::PhpFile,
			new Layer(LayerKind::Package, basename(self::PhpFile), self::Php, self::PhpDecision),
			$project,
		);
	}


	/**
	 * The packages shipping upgrading files, with the data of each.
	 * @return array<string, list<UpgradingData>>
	 */
	public function getDataPackages(): array
	{
		$res = [];
		foreach ($this->upgradingData as $data) {
			if ($data->layer->name !== null && $data->layer->package !== null) {
				$res[$data->layer->package][] = $data;
			}
		}

		return $res;
	}


	/**
	 * The plugins of the packages shipping upgrading files, which an upgrade lets in without `use` naming them.
	 * @return list<class-string<Plugin>>
	 */
	public function findDataPlugins(): array
	{
		return array_values(array_intersect_key($this->plugins, $this->getDataPackages()));
	}


	/**
	 * The packages shipping upgrading files whose data reach the project, with a map or a requirement for the versions
	 * the code must work with, which no set covers.
	 * @return list<string>
	 */
	public function findReachingPackages(): array
	{
		return array_keys(array_filter(
			$this->getDataPackages(),
			fn(array $files) => array_any($files, fn(UpgradingData $data) => $data->maps !== [] || $data->requirements !== []),
		));
	}


	/**
	 * The newest version the upgrading data of each library know of, PHP among them.
	 * @return array<string, string>  library => version
	 */
	public function findKnownVersions(): array
	{
		$versions = [];
		foreach ($this->upgradingData as $data) {
			if ($data->newest !== null && version_compare($data->newest, $versions[$data->package] ?? '0', '>')) {
				$versions[$data->package] = $data->newest;
			}
		}

		ksort($versions);
		return $versions;
	}


	/**
	 * The upgrading files of a package, relative to where it lies: every NEON file of the directory
	 * `extra.dresscode.upgrading-dir` names, in the order of their names.
	 * @param  array<mixed>  $extra  `extra.dresscode` of the package
	 * @return list<string>
	 * @throws ConfigurationException  for a directory that is not there
	 */
	public static function findUpgradingFiles(string $path, array $extra, string $package): array
	{
		$dir = $extra['upgrading-dir'] ?? null;
		if ($dir === null) {
			return [];
		} elseif (!is_string($dir) || !is_dir("$path/$dir")) {
			throw new ConfigurationException("Package `$package`: `extra.dresscode.upgrading-dir` in its `composer.json` names no directory of the package.");
		}

		$dir = rtrim($dir, '/');
		$files = array_map(fn(string $file) => "$dir/" . basename($file), glob("$path/$dir/*.neon") ?: []);
		sort($files);
		return $files;
	}


	/**
	 * The packages of upgrading data about a library the project requires itself, which neither the project nor the
	 * installation of DressCode holds.
	 * @param  array<string, list<string>>  $catalogue  package => the libraries it says it is about (`DataPackageCatalogue`)
	 * @param  ?array<string, string>  $installation  as `discover()` takes it
	 * @return list<string>
	 */
	public static function findMissingDataPackages(ProjectPackages $project, array $catalogue, ?array $installation = null): array
	{
		$installation ??= self::findInstalledDataPackages();
		$missing = [];
		foreach ($catalogue as $package => $libraries) {
			if ($project->has($package) || isset($installation[$package])) {
				continue;
			}

			if (array_any(array_keys($project->required), fn(string $name) => self::isAbout($libraries, $name))) {
				$missing[] = $package;
			}
		}

		return $missing;
	}


	/**
	 * Whether the library is one of those `extra.dresscode.packages` lists, a whole vendor written `vendor/`.
	 * @param  list<string>  $libraries
	 */
	public static function isAbout(array $libraries, string $library): bool
	{
		return array_any($libraries, fn(string $name) => str_ends_with($name, '/') ? str_starts_with($library, $name) : $library === $name);
	}


	/**
	 * The packages of upgrading data installed beside DressCode, as Composer says of the running process.
	 * @return array<string, string>  package => where it lies
	 */
	public static function findInstalledDataPackages(): array
	{
		$found = [];
		$packages = class_exists(InstalledVersions::class) ? InstalledVersions::getInstalledPackagesByType(self::PackageType) : [];
		foreach (array_unique($packages) as $package) {
			$path = InstalledVersions::getInstallPath($package);
			$extra = $path === null ? null : (ComposerJson::read("$path/composer.json")['extra']['dresscode'] ?? null);
			if (isset($extra['upgrading-dir'])) {
				$found[$package] = Helpers::canonicalizePath(realpath($path) ?: $path);
			}
		}

		return $found;
	}


	/**
	 * The directory of the `composer.json` DressCode is installed by, globally, beside a project or as the project;
	 * null for a phar and for a process Composer did not load.
	 */
	public static function findInstallationRoot(): ?string
	{
		$directory = self::findComposerDirectory();
		return $directory === null ? null : Helpers::canonicalizePath(dirname($directory, 2));
	}


	/** The directory of what Composer wrote about the installation the process is loaded from. */
	private static function findComposerDirectory(): ?string
	{
		$file = class_exists(InstalledVersions::class) ? new \ReflectionClass(InstalledVersions::class)->getFileName() : false;
		return $file === false || str_starts_with($file, 'phar://') ? null : dirname($file);
	}


	/**
	 * The plugin the package of the class names in `extra.dresscode.plugin`, the package being the nearest composer.json
	 * above the file of the class, as a test of a rule of a plugin finds the decisions its manifest declares; null where
	 * it names none.
	 * @param  class-string  $class
	 */
	public static function findPluginOf(string $class): ?Plugin
	{
		static $plugins = [];
		$file = new \ReflectionClass($class)->getFileName();
		$composerFile = $file === false ? null : ComposerJson::findFile(dirname($file));
		if ($composerFile === null) {
			return null;
		} elseif (!array_key_exists($composerFile, $plugins)) {
			$plugin = ComposerJson::read($composerFile)['extra']['dresscode']['plugin'] ?? null;
			$plugins[$composerFile] = is_string($plugin) && is_subclass_of($plugin, Plugin::class) ? new $plugin : null;
		}

		return $plugins[$composerFile];
	}


	/**
	 * The file as upgrading data for the versions of the package it is about the code must work with; null for a package
	 * the project does not have.
	 * @throws ConfigurationException
	 */
	private static function readFile(string $file, Layer $layer, ProjectPackages $project): ?UpgradingData
	{
		$upgrading = UpgradingFile::fromFile($file, $layer->formatReference());
		return $project->has($upgrading->package)
			? $upgrading->selectFor($project->findConstraint($upgrading->package), $layer)
			: null;
	}
}
