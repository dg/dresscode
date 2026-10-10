<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use Composer\InstalledVersions;
use DressCode\{Analyses, Config, ConfigurationException, Override, Plugin, PluginManifest, Profile, Rule};
use DressCode\Engine\{Baseline, FileProcessor, FileProcessors, Helpers, Profiler, ReportPolicy, ResultCache, Runner, TypeAnalysisStatus};
use Nette\Utils\Finder;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use function count, is_string;


/**
 * Resolves a configuration in a project and builds the runner of a run from it, and reads what the composer.json of
 * the project says of it.
 * @internal
 */
final readonly class RunnerFactory
{
	public function __construct(
		public PluginRegistry $registry = new PluginRegistry,
		/** whether `phpstan/phpstan` is installed beside DressCode; null asks Composer */
		private ?bool $phpstanInstalled = null,
	) {
	}


	/**
	 * Makes the rules and presets of the plugins and of the configuration known and resolves the configuration, every
	 * override included.
	 * @param  ?Profile  $commandLine  laid over the configuration and its overrides, as --use is
	 * @param  ?list<string>  $only  decisions, sections, presets and classes of rules the run is narrowed to
	 * @throws ConfigurationException
	 */
	public function resolve(Config $config, string $root, ?Profile $commandLine = null, ?array $only = null): ResolvedProject
	{
		$packageTargets = array_diff_key($config->targets, ['php' => true]);
		$project = ProjectPackages::read($root)->withTargets($packageTargets);
		$packages = PackageDiscovery::discover($project);
		[$packagePlugins, $unnamed] = $this->admitPackagePlugins($packages->plugins, $config, $commandLine, $project->rootName);
		$visited = [];
		$plugins = [
			...$this->loadPlugins($packagePlugins, $visited),
			...$this->loadPlugins($config->plugins, $visited),
			...$this->loadPlugins($commandLine instanceof Config ? $commandLine->plugins : [], $visited),
		];
		$this->registerProjectRules($config);
		[$target, $source] = $this->getPhpTarget($config, $root);
		$typesAvailable = $config->typeAnalysis === null || $this->isPhpStanInstalled();
		$resolver = new ConfigResolver($this->registry, $packages->upgradingData, $project, $typesAvailable, $root);
		$resolved = $resolver->resolve($config, $target, [], $commandLine, $only);
		$missing = array_map(
			fn(string $package) => $package === $project->rootName
				? "The configuration names package `$package` in `targets`, but that is the project itself, whose code is any version of it; skipped."
				: "The configuration names package `$package` in `targets`, but it is not installed; skipped.",
			array_filter(array_keys($packageTargets), fn(string $package) => !$project->has($package) || $package === $project->rootName),
		);
		$warnings = array_fill_keys([...$packages->warnings, ...$unnamed, ...$missing, ...$resolver->getWarnings()], null);
		if (!$typesAvailable) {
			$warnings['The configuration sets `typeAnalysis: phpstan`, but `phpstan/phpstan` is not installed beside DressCode, so the run goes without the types of the code.'] = 'types#enable';
		}

		return new ResolvedProject(
			$config,
			$root,
			$resolved,
			$source,
			$warnings,
			array_map(fn(UpgradingData $data) => [$data, $project->findVersion($data->package)], $packages->upgradingData),
			$plugins,
			$project,
			$commandLine,
			$only,
			$resolver,
			$target,
		);
	}


	/**
	 * @param  bool  $strict  a broken rule contract throws instead of warning
	 * @param  bool  $cache  clean files are remembered and skipped next time
	 * @param  ?string  $configFile  the file the configuration was read from; its text is all the cache knows about
	 *                                a rule or an analysis a closure builds
	 * @param  bool  $fixRisky  every fix that may change what the code does is made, not only those of the decisions
	 *                           the configuration names in fixRisky
	 * @param  bool  $baseline  the configured baseline leaves what it knows unrecorded; a run generating one sees everything
	 * @throws ConfigurationException
	 */
	public function createRunner(
		ResolvedProject $resolution,
		bool $strict = false,
		bool $cache = true,
		?string $configFile = null,
		bool $fixRisky = false,
		bool $baseline = true,
		?Profiler $profiler = null,
	): Runner
	{
		$config = $resolution->config;
		$root = $resolution->root;
		$resolved = $resolution->resolvedConfig;
		$layers = [...$resolution->pluginManifests, $config];
		$analyses = array_merge(...array_map(fn(Config|PluginManifest $layer) => $layer->analyses, $layers));
		if ($resolved->typeAnalysis === 'phpstan') {
			// one PHPStan reads every file, so it parses the newest syntax an override may target
			$phpVersion = $resolved->phpVersion;
			foreach ($resolved->overrides as $override) {
				$phpVersion = version_compare($override->phpVersion, $phpVersion, '>') ? $override->phpVersion : $phpVersion;
			}

			$phpstan = new Analyses\PhpStan($root, self::resolveAnalysedPaths($config, $root), self::resolveCacheDir($config, $root) . '/phpstan', $phpVersion, $profiler);
			$analyses[Analyses\Types::class] = fn(FileNode $file, string $path) => new Analyses\Types($file, $path, $phpstan);
		}

		$baselineFile = $baseline ? self::loadBaseline($config, $root) : null;

		$registry = $this->registry;
		$processors = new FileProcessors(
			array_map(fn(Override $override) => $override->paths, $config->overrides),
			function (array $overrides) use ($resolution, $registry, $analyses, $strict, $baselineFile, $fixRisky, $profiler): FileProcessor {
				$variant = $resolution->resolveFor($overrides);
				$style = $variant->createStyle();
				$analysisRegistry = $variant->createAnalyses($style);
				foreach ($analyses as $class => $factory) {
					$analysisRegistry->register($class, $factory);
				}

				return new FileProcessor(
					RuleBuilder::buildRules($variant),
					$analysisRegistry,
					$variant->phpVersion,
					$style,
					detectLineEnding: $variant->lineEnding === null,
					policy: new ReportPolicy(
						expandName: $registry->expandSuppressedName(...),
						suppressionComments: $variant->suppressionComments,
						baseline: $baselineFile,
						warnOnly: $variant->warnOnly,
						fixRisky: $fixRisky ?: $variant->fixRisky,
						strict: $strict,
					),
					profiler: $profiler,
					gates: $variant->getGates(),
				);
			},
		);
		$resultCache = $cache && ($configFile !== null || !self::hasClosures($resolved, $analyses))
			? ResultCache::load(
				self::resolveCacheFile($config, $root),
				// the baseline decides what a rule reports, so a file clean under one is not clean under another
				self::hashConfiguration([
					$resolved->toArray(),
					array_keys($analyses),
					$baselineFile?->getHash(),
					$config->overrides,
					$resolution->commandLine,
					$resolution->only,
					$fixRisky,
					$configFile === null ? null : hash_file('xxh128', $configFile),
					$this->collectSourceTimes($resolved, $analyses),
				], $resolution->projectPackages),
			)
			: null;
		return new Runner(
			$processors,
			$root,
			array_values(array_unique(array_merge(...array_map(fn(Config|PluginManifest $layer) => $layer->excludePaths, $layers)))),
			$config->fileExtensions,
			self::combineSkipWhen($layers),
			$baselineFile,
			$resultCache,
			narrowed: (bool) $resolution->only,
			profiler: $profiler,
			warmUp: isset($phpstan) ? $phpstan->warmUp(...) : null,
			typeAnalysis: match (true) {
				$resolved->typeAnalysis !== null => TypeAnalysisStatus::Enabled,
				$this->isPhpStanInstalled() => TypeAnalysisStatus::Available,
				default => TypeAnalysisStatus::Unavailable,
			},
			namespacesListed: $resolved->namespacedFunctions !== [] || $resolved->namespacedConstants !== [],
		);
	}


	private function isPhpStanInstalled(): bool
	{
		return $this->phpstanInstalled ?? Analyses\PhpStan::isAvailable();
	}


	/**
	 * The plugins of the packages the project lets in, by naming the package or the plugin in `use`, its own package
	 * needing no name, and a warning for each of the others; a package named makes its name no preset.
	 * @param  array<string, class-string<Plugin>>  $plugins  package => its plugin
	 * @return array{list<class-string<Plugin>>, list<string>}
	 */
	private function admitPackagePlugins(array $plugins, Config $config, ?Profile $commandLine, ?string $rootName): array
	{
		$named = [
			...$config->use,
			...$config->plugins,
			...$commandLine->use ?? [],
			...($commandLine instanceof Config ? $commandLine->plugins : []),
		];
		$admitted = $warnings = [];
		foreach ($plugins as $package => $plugin) {
			if ($package === $rootName || in_array($package, $named, true) || in_array($plugin, $named, true)) {
				$admitted[] = $plugin;
				$this->registry->registerPluginPackage($package);
			} else {
				$warnings[] = "Package `$package` brings the plugin `$plugin`, which loads only where `use` names the package or the plugin; skipped.";
			}
		}

		return [$admitted, $warnings];
	}


	/**
	 * Makes the rules and presets the plugins bring known and returns what they bring, each class once and the plugins
	 * a plugin builds on before it, registered before it too.
	 * @param  list<class-string<Plugin>|Plugin>  $plugins
	 * @param  array<class-string<Plugin>, false|list<string>>  $visited  the plugins loaded with the sections they and the plugins
	 *   they build on have, false for one whose dependencies are being loaded
	 * @return list<PluginManifest>
	 * @throws ConfigurationException
	 */
	private function loadPlugins(array $plugins, array &$visited): array
	{
		$manifests = [];
		foreach ($plugins as $plugin) {
			$class = is_string($plugin) ? $plugin : $plugin::class;
			if (($visited[$class] ?? null) === false) {
				$path = [...array_keys(array_filter($visited, fn(array|false $loaded) => $loaded === false)), $class];
				throw new ConfigurationException('Plugins depend on each other in a cycle: `' . implode('` -> `', array_slice($path, (int) array_search($class, $path, true))) . '`.');
			} elseif (isset($visited[$class])) {
				continue;
			}

			$visited[$class] = false;
			$plugin = is_string($plugin) ? new $plugin : $plugin;
			$manifest = $plugin->getManifest();
			if (($manifest->rules !== [] || $manifest->decisions !== []) && $manifest->section === null) {
				throw new ConfigurationException("Plugin `$class` brings rules but names no section for their decisions; give its manifest a `section` named after the plugin.");
			}

			$dependencies = $this->loadPlugins($manifest->plugins, $visited);
			$reached = [];
			foreach ($manifest->plugins as $dependency) {
				array_push($reached, ...$visited[is_string($dependency) ? $dependency : $dependency::class] ?: []);
			}

			$reached = array_values(array_unique($reached));
			if ($manifest->section !== null) {
				$this->registry->registerDependencies($manifest->section, $reached);
			}

			if ($manifest->section !== null && $manifest->decisions !== []) {
				$this->registry->registerDecisions($manifest->section, $manifest->decisions);
			}

			foreach ($manifest->rules as $rule) {
				$this->registry->registerRule($rule, $manifest->ruleUrl, $manifest->section);
			}

			foreach ($manifest->presets as $name => $file) {
				$this->registry->registerPreset($name, $file);
			}

			$visited[$class] = $manifest->section === null ? $reached : [$manifest->section, ...$reached];
			$manifests = [...$manifests, ...$dependencies, $manifest];
		}

		return $manifests;
	}


	/**
	 * Makes the rules of the project known by their classes, with the address of their page the configuration gives.
	 * @throws ConfigurationException
	 */
	private function registerProjectRules(Config $config): void
	{
		foreach (array_keys($config->rules) as $class) {
			$this->registry->registerRule($class, $config->ruleUrl);
		}
	}


	/**
	 * A file any of the configurations skips is skipped.
	 * @param  list<Config|PluginManifest>  $configs
	 * @return ?\Closure(string, string): bool
	 */
	private static function combineSkipWhen(array $configs): ?\Closure
	{
		$filters = array_values(array_filter(array_map(fn(Config|PluginManifest $config) => $config->skipWhen, $configs)));
		return match (count($filters)) {
			0 => null,
			1 => $filters[0],
			default => fn(string $content, string $path): bool => array_any($filters, fn(\Closure $filter) => $filter($content, $path)),
		};
	}


	/** The cache file of the project root: in the configured directory, else in the system temp. */
	private static function resolveCacheFile(Config $config, string $root): string
	{
		return self::resolveCacheDir($config, $root) . '/' . substr(hash('xxh128', Helpers::canonicalizePath($root)), 0, 16) . '.json';
	}


	private static function resolveCacheDir(Config $config, string $root): string
	{
		$root = Helpers::canonicalizePath($root);
		$dir = $config->cacheDir === null ? sys_get_temp_dir() . '/dresscode' : Helpers::toAbsolutePath($config->cacheDir, $root);
		return Helpers::canonicalizePath($dir);
	}


	/**
	 * Where PHPStan looks for the declarations of the project besides its Composer autoload: the configured paths
	 * that exist, else the root.
	 * @return list<string>
	 */
	private static function resolveAnalysedPaths(Config $config, string $root): array
	{
		$paths = array_values(array_filter(
			array_map(fn(string $path) => Helpers::toAbsolutePath($path, $root), $config->paths),
			fn(string $path) => is_dir($path) || is_file($path),
		));
		return $paths === [] ? [$root] : $paths;
	}


	/**
	 * The identity of everything a result depends on besides the file: what the caller gives, the packages of the
	 * project, and those of the running process, which are the tool, its PHPStan and the extensions of it wherever
	 * they are not installed in the project.
	 * @param  array<mixed>  $configuration
	 */
	private static function hashConfiguration(array $configuration, ProjectPackages $project): string
	{
		return hash('xxh128', json_encode([$configuration, $project->getIdentity(), self::getProcessIdentity()], JSON_PARTIAL_OUTPUT_ON_ERROR));
	}


	/**
	 * The packages of the running process with the version and the reference of the source each came from, so that
	 * a package upgraded where the project does not see it invalidates what was cached; the root package is left out,
	 * its files being weighed by their modification times.
	 * @return array<string, array{?string, ?string}>  package => version and reference
	 */
	public static function getProcessIdentity(): array
	{
		$identity = [];
		foreach (self::getProcessInstallations() as $data) {
			foreach ($data['versions'] as $name => $package) {
				if ($name !== $data['root']['name'] && isset($package['install_path'])) {
					$identity[$name] ??= [$package['version'] ?? null, $package['reference'] ?? null];
				}
			}
		}

		ksort($identity);
		return $identity;
	}


	/**
	 * Where the packages of the running process lie, which is what tells a file of a rule the run builds from
	 * a package apart from a file of the project itself. The path of the root package is null, its files being the
	 * ones the caller weighs.
	 * @return array<string, ?string>  package => where it lies
	 */
	private static function getProcessPackagePaths(): array
	{
		$packages = [];
		foreach (self::getProcessInstallations() as $data) {
			foreach ($data['versions'] as $name => $package) {
				$packages[$name] ??= $name === $data['root']['name'] ? null : ($package['install_path'] ?? null);
			}
		}

		return $packages;
	}


	/**
	 * What Composer says of the installations the running process is loaded from: InstalledVersions answers from
	 * every registered loader, the one inside the phar of PHPStan included once it is started, so a root of a phar
	 * is left out.
	 * @return list<array{root: array{name: string, install_path: string}, versions: array<string, array{version?: string, reference?: ?string, install_path?: string}>}>
	 */
	private static function getProcessInstallations(): array
	{
		return class_exists(InstalledVersions::class)
			? array_values(array_filter(InstalledVersions::getAllRawData(), fn(array $data) => !str_starts_with($data['root']['install_path'], 'phar://')))
			: [];
	}


	/**
	 * The modification times of the files the rules and analyses of the run are declared in, their parents
	 * and traits included, and of the code and the data of the trees of the tool and of the syntax tree, where neither a package of the running process nor a phar holds them: the version of
	 * a package stands for its files, while a rule of the project itself changes under the same version of the
	 * project. A process without Composer knows no package, so every file outside a phar counts.
	 * @param  array<class-string, ?\Closure(FileNode, string): object>  $analyses
	 * @return array<string, int|false>  file => modification time
	 */
	private function collectSourceTimes(ResolvedConfig $resolved, array $analyses): array
	{
		$packages = [];
		foreach (self::getProcessPackagePaths() as $path) {
			$path = $path === null ? false : realpath($path);
			if ($path !== false) {
				$packages[] = Helpers::canonicalizePath($path) . '/';
			}
		}

		$classes = [
			...$this->registry->rules,
			...array_map(fn(ResolvedRule $rule) => $rule->class, $resolved->rules),
			...array_keys($analyses),
		];
		$times = [];

		// the trees of the tool and of the syntax tree, whose every helper and every file of data a rule may stand on
		foreach ([Rule::class, Node::class] as $class) {
			$dir = Helpers::canonicalizePath(dirname((string) new \ReflectionClass($class)->getFileName())) . '/';
			if (!str_starts_with($dir, 'phar://') && !array_any($packages, fn(string $path) => str_starts_with($dir, $path))) {
				foreach (Finder::findFiles('*.php', '*.neon')->from($dir) as $file) {
					$times[Helpers::canonicalizePath($file->getPathname())] = $file->getMTime();
				}
			}
		}

		foreach (array_unique($classes) as $class) {
			for ($reflection = new \ReflectionClass($class); $reflection; $reflection = $reflection->getParentClass()) {
				foreach ([$reflection, ...array_values($reflection->getTraits())] as $declaring) {
					$file = $declaring->getFileName();
					$file = $file === false ? null : Helpers::canonicalizePath($file);
					if (
						$file !== null
						&& !str_starts_with($file, 'phar://')
						&& !isset($times[$file])
						&& !array_any($packages, fn(string $path) => str_starts_with($file, $path))
					) {
						$times[$file] = filemtime($file);
					}
				}
			}
		}

		ksort($times);
		return $times;
	}


	/**
	 * Whether a closure builds a rule or an analysis of the run, which the resolved configuration cannot describe.
	 * @param  array<class-string, ?\Closure(FileNode, string): object>  $analyses
	 */
	private static function hasClosures(ResolvedConfig $resolved, array $analyses): bool
	{
		return array_any($resolved->rules, fn(ResolvedRule $rule) => $rule->factory !== null)
			|| array_filter($analyses) !== [];
	}


	/** The configured baseline when its file exists; before the first generation there is none. */
	public static function loadBaseline(Config $config, string $root): ?Baseline
	{
		return $config->baseline === null ? null : Baseline::load(Helpers::toAbsolutePath($config->baseline, $root));
	}


	/**
	 * The versions the code is written for unless an override says another, as a Composer constraint: the configured
	 * one, or those the nearest composer.json allows, or the default one. The running version is never the answer: it
	 * says nothing about the code being checked.
	 * @return array{string, PhpVersionSource}
	 */
	public function getPhpTarget(Config $config, string $root): array
	{
		$php = $config->targets['php'] ?? null;
		$detected = $php === null ? Composer::detectPhpTarget(Composer::findFile($root)) : null;
		return match (true) {
			$php !== null => [$php, PhpVersionSource::Configuration],
			$detected !== null => [$detected, PhpVersionSource::Composer],
			default => [Config::DefaultPhpVersion, PhpVersionSource::Default],
		};
	}
}
