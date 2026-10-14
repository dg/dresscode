<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use Composer\InstalledVersions;
use DressCode\{Analyses, Config, ConfigurationException, Override, Plugin, PluginManifest, Profile, Rule};
use DressCode\Engine\{
	Baseline, FileProcessor, FileProcessors, Helpers, PhpFileProcessor, ReportPolicy, ResultCache, Runner, TypeAnalysisStatus,
};
use Nette\Utils\Finder;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use function count, is_string, strlen;


/**
 * Resolves a configuration in a project and builds the runner of a run from it; what the composer.json of the
 * project says is read by `ComposerJson` and `ProjectPackages`.
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
	 * @param  list<string>  $laid  presets laid over those of the configuration and beneath its decisions and overrides
	 * @throws ConfigurationException
	 */
	public function resolve(
		Config $config,
		string $root,
		?Profile $commandLine = null,
		?array $only = null,
		array $laid = [],
	): ResolvedProject
	{
		[$target, $source] = $this->getPhpTarget($config, $root);
		$project = $this->readProjectPackages($config, $root, $target);
		$packages = PackageDiscovery::discover($project);
		[$packagePlugins, $skipped, $unnamed] = $this->admitPackagePlugins(
			$packages->plugins,
			array_keys($packages->getDataPackages()),
			$config,
			$commandLine,
			$project->rootName,
		);
		$visited = [];
		$plugins = [
			...$this->loadPlugins($packagePlugins, $visited),
			...$this->loadPlugins($config->plugins, $visited),
			...$this->loadPlugins($commandLine instanceof Config ? $commandLine->plugins : [], $visited),
		];
		$this->registerProjectRules($config);
		// a command asking for the types has no run without them
		$commandTypes = $commandLine instanceof Config ? $commandLine->typeAnalysis : null;
		$typesAvailable = ($commandTypes ?? $config->typeAnalysis) === null || $this->isPhpStanInstalled();
		if ($commandTypes !== null && !$typesAvailable) {
			throw new ConfigurationException(
				'The command needs the types of the code, but `phpstan/phpstan` is not installed beside DressCode.',
				docs: 'types#enable',
			);
		}

		$resolver = new ConfigResolver($this->registry, $packages->upgradingData, $project, $typesAvailable, $root, $skipped, $laid);
		$resolved = $resolver->resolve($config, $target, [], $commandLine, $only);
		$missing = array_map(
			fn(string $package) => $package === $project->rootName
				? "The configuration names package `$package` in `targets`, but that is the project itself, whose code is any version of it; skipped."
				: "The configuration names package `$package` in `targets`, but it is not installed; skipped.",
			array_filter(
				array_keys(array_diff_key($config->targets, [PackageDiscovery::Php => true])),
				fn(string $package) => !$project->has($package) || $package === $project->rootName,
			),
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
			$laid,
		);
	}


	/**
	 * @param  bool  $strict  a broken rule contract throws instead of warning
	 * @param  bool  $cache  clean files are remembered and skipped next time
	 * @param  bool  $refreshCache  no file is skipped, while what the run finds is remembered
	 * @param  ?string  $configFile  the file the configuration was read from; its text is all the cache knows about
	 *                                a rule or an analysis a closure builds
	 * @param  bool  $fixRisky  every fix that may change what the code does is made, not only those of the decisions
	 *                           the configuration names in fixRisky
	 * @param  bool  $baseline  the configured baseline leaves what it knows unrecorded; a run generating one sees everything
	 * @param  list<string>  $leftAsWritten  decisions the file broke before an edit, left as the file writes them
	 * @throws ConfigurationException
	 */
	public function createRunner(
		ResolvedProject $resolution,
		bool $strict = false,
		bool $cache = true,
		?string $configFile = null,
		bool $fixRisky = false,
		bool $baseline = true,
		bool $refreshCache = false,
		array $leftAsWritten = [],
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

			$phpstan = new Analyses\PhpStan(
				$root,
				self::resolveAnalysedPaths($config, $root),
				self::resolveCacheDir($config, $root) . '/phpstan',
				$phpVersion,
				$resolution->projectPackages->findNewerInstalledPaths(),
				$resolution->projectPackages->findUnboundedInstalledPaths(),
			);
			$analyses[Analyses\Types::class] = fn(FileNode $file, string $path) => new Analyses\Types($file, $path, $phpstan);
		}

		$baselineFile = $baseline ? self::loadBaseline($config, $root) : null;

		$registry = $this->registry;
		$processors = new FileProcessors(
			array_map(fn(Override $override) => $override->paths, $config->overrides),
			function (array $overrides) use (
				$resolution, $registry, $analyses, $strict, $baselineFile, $fixRisky, $leftAsWritten,
			): FileProcessor {
				$variant = $resolution->resolveFor($overrides);
				$style = $variant->createStyle();
				$analysisRegistry = $variant->createAnalyses($style);
				foreach ($analyses as $class => $factory) {
					$analysisRegistry->register($class, $factory);
				}

				return new PhpFileProcessor(
					RuleBuilder::buildRules($variant),
					$analysisRegistry,
					$variant->phpVersion,
					$style,
					detectLineEnding: $variant->lineEnding === null,
					policy: new ReportPolicy(
						expandName: $registry->expandSuppressedName(...),
						suppressionComments: $variant->suppressionComments,
						baseline: $baselineFile,
						warningDecisions: $variant->warningDecisions,
						fixRisky: $fixRisky ?: $variant->fixRisky,
						reportOnly: $variant->reportOnly,
						leftAsWritten: array_fill_keys($leftAsWritten, true),
						strict: $strict,
					),
					gates: $variant->getGates(),
				);
			},
		);
		$now = time();
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
					$this->stampSources($resolved, $analyses, $now),
					// with the types a result depends on what the rest of the project declares
					$resolved->typeAnalysis === null ? null : [
						$resolution->projectPackages->findNewerInstalledPaths(),
						$resolution->projectPackages->findUnboundedInstalledPaths(),
						self::fingerprintProject($config, $root, $now),
					],
				], $resolution->projectPackages),
				serves: !$refreshCache,
				// @ a file gone since is a changed one
				stamp: fn(string $file) => ($stat = @stat($file)) === false ? '' : self::stampFile($file, $stat[9], $stat[7], $now),
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
			warmUp: isset($phpstan) ? $phpstan->warmUp(...) : null,
			findDependencies: isset($phpstan) ? $phpstan->getConfigurationFiles(...) : null,
			typeAnalysis: match (true) {
				$resolved->typeAnalysis !== null => TypeAnalysisStatus::Enabled,
				$this->isPhpStanInstalled() => TypeAnalysisStatus::Available,
				default => TypeAnalysisStatus::Unavailable,
			},
			typesMissing: array_any($resolved->rules, fn(ResolvedRule $rule) => $rule->inactiveReason === InactiveReason::Types),
			namespacesListed: $resolved->namespacedFunctions !== [] || $resolved->namespacedConstants !== [],
		);
	}


	private function isPhpStanInstalled(): bool
	{
		return $this->phpstanInstalled ?? Analyses\PhpStan::isAvailable();
	}


	/**
	 * The plugins of the packages the project lets in, by naming the package, one of the vendor of the core with or
	 * without it, or the plugin in `use`, its own package needing no name, the others skipped with a warning; a package
	 * named makes its name no preset.
	 * @param  array<string, class-string<Plugin>>  $plugins  package => its plugin
	 * @param  list<string>  $carriers  the packages shipping upgrading data, which `upgrade` lets in by itself
	 * @return array{list<class-string<Plugin>>, list<string>, list<string>}  the plugins, the packages skipped, the warnings
	 */
	private function admitPackagePlugins(array $plugins, array $carriers, Config $config, ?Profile $commandLine, ?string $rootName): array
	{
		$named = [
			...$config->use,
			...$config->plugins,
			...$commandLine->use ?? [],
			...($commandLine instanceof Config ? $commandLine->plugins : []),
		];
		$admitted = $skipped = $warnings = [];
		foreach ($plugins as $package => $plugin) {
			$short = PluginRegistry::abbreviate($package);
			if ($package === $rootName || array_any([$package, $short, $plugin], fn(string $name) => in_array($name, $named, true))) {
				$admitted[] = $plugin;
				$this->registry->registerPluginPackage($package, $plugin);
			} elseif (in_array($package, $carriers, true)) {
				$skipped[] = $package;
				$warnings[] = "Package `$package` brings rules that run only where `use` names it"
					. ($short === $package ? '' : ", as `$short`")
					. ', and in `dresscode upgrade`; skipped, with what its upgrading files set for them.';
			} else {
				$skipped[] = $package;
				$warnings[] = "Package `$package` brings the plugin `$plugin`, which loads only where `use` names the package or the plugin; skipped.";
			}
		}

		return [$admitted, $skipped, $warnings];
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
				$path = array_slice($path, (int) array_search($class, $path, strict: true));
				throw new ConfigurationException('Plugins depend on each other in a cycle: `' . implode('` -> `', $path) . '`.');
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
				$this->registry->registerPluginSection($class, $manifest->section);
				$this->registry->registerDependencies($manifest->section, $reached);
			}

			if ($manifest->section !== null && $manifest->decisions !== []) {
				$this->registry->registerDecisions($manifest->section, $manifest->decisions);
			}

			foreach ($manifest->rules as $rule => $factory) {
				$this->registry->registerRule($rule, $manifest->ruleUrl, $manifest->section, $factory);
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


	public static function resolveCacheDir(Config $config, string $root): string
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
		return hash(
			'xxh128',
			json_encode([$configuration, $project->getIdentity(), self::getProcessIdentity()], JSON_PARTIAL_OUTPUT_ON_ERROR),
		);
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
	 * @return list<array{root: array{name: string, install_path: string},
	 *   versions: array<string, array{version?: string, reference?: ?string, install_path?: string}>}>
	 */
	private static function getProcessInstallations(): array
	{
		return class_exists(InstalledVersions::class)
			? array_values(array_filter(
				InstalledVersions::getAllRawData(),
				fn(array $data) => !str_starts_with($data['root']['install_path'], 'phar://'),
			))
			: [];
	}


	/**
	 * What the types of a file depend on besides the file itself: the stamp of every file under the analysed paths,
	 * outside the excluded ones, of the configuration of PHPStan and of the packages installed. A declaration changed
	 * anywhere then invalidates every result, which costs the cache of a run with the types its use after an edit and
	 * keeps a clean verdict true. What that configuration includes, its stubs and bootstrap files outside those paths,
	 * only the container of PHPStan knows, so the run that builds one records them in the cache, which the next run
	 * drops where any of them changed.
	 */
	private static function fingerprintProject(Config $config, string $root, int $now): string
	{
		$hash = hash_init('xxh128');
		foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon', 'vendor/composer/installed.json'] as $name) {
			$stat = @stat("$root/$name"); // @ an optional file may not exist
			hash_update($hash, $name . ($stat === false ? '' : '|' . self::stampFile("$root/$name", $stat[9], $stat[7], $now)) . "\n");
		}

		// the iterators of SPL rather than Finder: the walk runs at the start of every such run, and the listing of
		// a directory gives the time and size of its files at once
		$prefix = strlen(Helpers::canonicalizePath($root)) + 1;
		$extensions = array_flip($config->fileExtensions);
		foreach (self::resolveAnalysedPaths($config, $root) as $path) {
			$files = is_file($path) ? [new \SplFileInfo($path)] : new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
				fn(\SplFileInfo $file) => !$file->isDir()
					|| !Helpers::matchesAny($config->excludePaths, substr(Helpers::canonicalizePath($file->getPathname()), $prefix)),
			));
			foreach ($files as $file) {
				if (isset($extensions[$file->getExtension()])) {
					$stamp = self::stampFile($file->getPathname(), (int) $file->getMTime(), (int) $file->getSize(), $now);
					hash_update($hash, $file->getPathname() . '|' . $stamp . "\n");
				}
			}
		}

		return hash_final($hash);
	}


	/**
	 * The stamps of the files the rules and analyses of the run are declared in, their parents and traits included,
	 * and of the code and the data of the trees of the tool and of the syntax tree, where neither a package of the
	 * running process nor a phar holds them: the version of a package stands for its files, while a rule of the project
	 * itself changes under the same version of the project. A process without Composer knows no package, so every file
	 * outside a phar counts.
	 * @param  array<class-string, ?\Closure(FileNode, string): object>  $analyses
	 * @return array<string, string>  file => stamp
	 */
	private function stampSources(ResolvedConfig $resolved, array $analyses, int $now): array
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
		$stamps = [];

		// the trees of the tool and of the syntax tree, whose every helper and every file of data a rule may stand on
		foreach ([Rule::class, Node::class] as $class) {
			$dir = Helpers::canonicalizePath(dirname((string) new \ReflectionClass($class)->getFileName())) . '/';
			if (!str_starts_with($dir, 'phar://') && !array_any($packages, fn(string $path) => str_starts_with($dir, $path))) {
				foreach (Finder::findFiles('*.php', '*.neon')->from($dir) as $file) {
					$stamps[Helpers::canonicalizePath($file->getPathname())] = self::stampFile(
						$file->getPathname(),
						(int) $file->getMTime(),
						(int) $file->getSize(),
						$now,
					);
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
						&& !isset($stamps[$file])
						&& !array_any($packages, fn(string $path) => str_starts_with($file, $path))
					) {
						$stamps[$file] = self::stampFile($file, (int) filemtime($file), (int) filesize($file), $now);
					}
				}
			}
		}

		ksort($stamps);
		return $stamps;
	}


	/**
	 * The time and size of a file, and the hash of its text where it was modified in the second before the run began
	 * or later, when a change within the same second would leave both as they were. A file restored with an older
	 * time and the same size is not told from the one it replaced.
	 */
	private static function stampFile(string $path, int $time, int $size, int $now): string
	{
		return "$time|$size" . ($time >= $now - 1 ? '|' . hash_file('xxh128', $path) : '');
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
		$detected = $php === null ? ComposerJson::detectPhpTarget(ComposerJson::findFile($root)) : null;
		return match (true) {
			$php !== null => [$php, PhpVersionSource::Configuration],
			$detected !== null => [$detected, PhpVersionSource::Composer],
			default => [Config::DefaultPhpVersion, PhpVersionSource::Default],
		};
	}


	/**
	 * The packages of the project with the versions the configuration says, PHP among them by the target, which a caller
	 * that has it already gives.
	 */
	public function readProjectPackages(Config $config, string $root, ?string $phpTarget = null): ProjectPackages
	{
		return ProjectPackages::read($root)
			->withTargets(array_diff_key($config->targets, [PackageDiscovery::Php => true]))
			->withPhp($phpTarget ?? $this->getPhpTarget($config, $root)[0]);
	}
}
