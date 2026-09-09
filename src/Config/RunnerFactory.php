<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use Composer\InstalledVersions;
use DressCode\{Analyses, Config, ConfigurationException, Extension, Helpers, Override, Preset, Profile, Rule, Runner, Style};
use DressCode\Engine\{Baseline, FileProcessor, ResultCache};
use Nette\Utils\FileSystem;
use PhpSyntax\Nodes\FileNode;
use function array_slice, count, dirname, in_array, is_array, is_string, strlen;
use const JSON_PARTIAL_OUTPUT_ON_ERROR, JSON_THROW_ON_ERROR;


/**
 * Builds the runner of a run from a configuration, and reads what the composer.json of the project says of it.
 * @internal
 */
final class RunnerFactory
{
	/** what an extension may set; everything else is for the project to decide */
	private const ExtensionKeys = ['extensions', 'analyses', 'excludePaths', 'skipWhen'];

	/** @var list<string> */
	private array $warnings = [];

	/** @var ?array{string, PhpVersionSource} */
	private ?array $phpVersion = null;

	private ?ResolvedConfig $resolved = null;

	/** @var ?\Closure(list<int>): ResolvedConfig */
	private ?\Closure $resolveFor = null;


	public function __construct(
		private readonly RuleRegistry $registry = new RuleRegistry,
	) {
	}


	/**
	 * What the last built engine has to say about the configuration it was built from.
	 * @return list<string>
	 */
	public function getWarnings(): array
	{
		return $this->warnings;
	}


	/**
	 * The version the last built engine targets and where it came from; the caller must not resolve it
	 * again, or the header could name something else than the rules were chosen for.
	 * @return array{string, PhpVersionSource}
	 */
	public function getPhpVersion(): array
	{
		return $this->phpVersion ?? throw new \LogicException('No engine has been built yet.');
	}


	/** The configuration the last built engine came from, as data. */
	public function getResolvedConfig(): ResolvedConfig
	{
		return $this->resolved ?? throw new \LogicException('No engine has been built yet.');
	}


	/**
	 * @param  ?Profile  $commandLine  laid over the configuration and its overrides, as --preset and --rule are
	 * @param  ?list<string>  $only  names or classes of the rules and presets the run is narrowed to
	 * @param  bool  $strict  a broken rule contract throws instead of warning
	 * @param  bool  $cache  clean files are remembered and skipped next time
	 * @param  ?string  $configFile  the file the configuration was read from; its text is all the cache knows about
	 *                                a rule or an analysis a closure builds
	 * @param  bool  $fixRisky  every fix that may change what the code does is made, not only those of the rules
	 *                           the configuration names in fixRisky
	 * @param  bool  $baseline  the configured baseline leaves what it knows unrecorded; a run generating one sees everything
	 * @throws ConfigurationException
	 */
	public function createRunner(
		Config $config,
		string $root,
		?Profile $commandLine = null,
		?array $only = null,
		bool $strict = false,
		bool $cache = true,
		?string $configFile = null,
		bool $fixRisky = false,
		bool $baseline = true,
	): Runner
	{
		$project = ProjectPackages::read($root);
		$packages = PackageProfiles::discover($project);
		$visited = [];
		$layers = [...$this->loadExtensions([...$packages->extensions, ...$config->extensions], $visited), $config];
		[$version, $source] = $this->resolvePhpVersion($config, $root);
		$resolver = new PresetResolver($this->registry, $packages->profiles, $project);
		$this->resolved = $resolved = $resolver->resolve($config, $version, [], $commandLine, $only);
		// an override is resolved for a file it matches, so a name or an option it gets wrong would pass unnoticed until
		// such a file comes; each of them is resolved as soon as the run is built
		foreach (array_keys($config->overrides) as $index) {
			$resolver->resolve($config, $version, [$index], $commandLine, $only);
		}

		$this->warnings = [...$packages->warnings, ...$resolver->getWarnings()];
		$this->phpVersion = [$resolved->phpVersion, $source];
		$analyses = array_merge(...array_map(fn(Config $layer) => $layer->analyses, $layers));
		if ($resolved->types === 'phpstan') {
			if (!Analyses\PhpStan::isAvailable()) {
				throw new ConfigurationException("The configuration sets 'types: phpstan', but phpstan/phpstan is not installed in the project.");
			}

			$phpstan = new Analyses\PhpStan($root, self::resolveAnalysedPaths($config, $root), self::resolveCacheDir($config, $root) . '/phpstan');
			$analyses[Analyses\Types::class] = fn(FileNode $file, string $path) => new Analyses\Types($file, $path, $phpstan);
		}

		$baselineFile = $baseline ? self::loadBaseline($config, $root) : null;

		// the processors are built lazily, so they must not ask the factory, which may have built another runner since
		$this->resolveFor = $resolveFor = fn(array $overrides) => $overrides === []
			? $resolved
			: $resolver->resolve($config, $version, array_values($overrides), $commandLine, $only);
		$registry = $this->registry;
		$processors = new FileProcessors(
			array_map(fn(Override $override) => $override->paths, $config->overrides),
			function (array $overrides) use ($resolver, $resolveFor, $registry, $analyses, $strict, $baselineFile, $fixRisky): FileProcessor {
				$variant = $resolveFor($overrides);
				$analysisRegistry = new Analyses\Registry($variant->toNamespacedSymbols());
				foreach ($analyses as $class => $factory) {
					$analysisRegistry->register($class, $factory);
				}

				$warningRules = $fixRiskyRules = [];
				foreach ($variant->getActiveRules() as $rule) {
					if ($rule->warning) {
						$warningRules[$rule->name] = true;
					}

					if ($rule->fixRisky) {
						$fixRiskyRules[$rule->name] = true;
					}
				}

				return new FileProcessor(
					$resolver->build($variant),
					$analysisRegistry,
					$registry->resolveNames(...),
					$variant->phpVersion,
					new Style($variant->indent, $variant->eol === 'majority' ? "\n" : $variant->eol, lineLength: $variant->lineLength),
					detectEol: $variant->eol === 'majority',
					strict: $strict,
					baseline: $baselineFile,
					warningRules: $warningRules,
					fixRisky: $fixRisky,
					fixRiskyRules: $fixRiskyRules,
				);
			},
		);
		$resultCache = $cache && ($configFile !== null || !self::hasClosures($config, $resolved, $analyses))
			? ResultCache::load(
				self::resolveCacheFile($config, $root),
				// the baseline decides what a rule reports, so a file clean under one is not clean under another
				self::hashConfiguration([
					$resolved->toArray(),
					array_keys($analyses),
					$baselineFile?->getHash(),
					$config->overrides,
					$commandLine,
					$only,
					$fixRisky,
					$configFile === null ? null : hash_file('xxh128', $configFile),
					$this->collectSourceTimes($resolved, $analyses),
				], $project),
			)
			: null;
		return new Runner(
			$processors,
			$root,
			array_values(array_unique(array_merge(...array_map(fn(Config $layer) => $layer->excludePaths, $layers)))),
			$config->fileExtensions,
			self::combineSkipWhen($layers),
			$baselineFile,
			$resultCache,
			narrowed: (bool) $only,
		);
	}


	/**
	 * What the configuration comes to for a file matching those overrides; the same resolution the run uses.
	 * @param  list<int>  $overrides
	 */
	public function resolveConfigFor(array $overrides): ResolvedConfig
	{
		return ($this->resolveFor ?? throw new \LogicException('No engine has been built yet.'))($overrides);
	}


	/**
	 * Makes the rules and presets named among the extensions known and returns what the extensions bring, each class once
	 * and the ones an extension names before it.
	 * @param  list<string|Extension>  $extensions
	 * @param  array<string, true>  $visited
	 * @return list<Config>
	 * @throws ConfigurationException
	 */
	private function loadExtensions(array $extensions, array &$visited): array
	{
		$configs = [];
		foreach ($extensions as $extension) {
			if (is_string($extension)) {
				if (isset($visited[$extension])) {
					continue;
				}

				$visited[$extension] = true;
				if (!class_exists($extension)) {
					throw new ConfigurationException("Extension class $extension does not exist.");
				} elseif (is_subclass_of($extension, Rule::class)) {
					$this->registry->registerRule($extension);
					continue;
				} elseif (is_subclass_of($extension, Preset::class)) {
					$this->registry->registerPreset($extension);
					continue;
				} elseif (!is_subclass_of($extension, Extension::class)) {
					throw new ConfigurationException("Extension $extension is not an extension, a rule or a preset.");
				}

				$extension = new $extension;
			}

			$provided = $extension->getConfig();
			$defaults = get_object_vars(new Config);
			foreach (get_object_vars($provided) as $key => $value) {
				if (!in_array($key, self::ExtensionKeys, true) && $value !== $defaults[$key]) {
					throw new ConfigurationException('Extension ' . $extension::class . " sets $key, which is for the project to decide; an extension sets " . implode(', ', self::ExtensionKeys) . '.');
				}
			}

			$configs = [...$configs, ...$this->loadExtensions($provided->extensions, $visited), $provided];
		}

		return $configs;
	}


	/**
	 * A file any of the configurations skips is skipped.
	 * @param  list<Config>  $configs
	 * @return ?\Closure(string, string): bool
	 */
	private static function combineSkipWhen(array $configs): ?\Closure
	{
		$filters = array_values(array_filter(array_map(fn(Config $config) => $config->skipWhen, $configs)));
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
		$dir = $config->cacheDir === null ? sys_get_temp_dir() . '/dresscode' : self::toAbsolutePath($config->cacheDir, $root);
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
			array_map(fn(string $path) => self::toAbsolutePath($path, $root), $config->paths),
			fn(string $path) => is_dir($path) || is_file($path),
		));
		return $paths === [] ? [$root] : $paths;
	}


	/**
	 * The identity of everything a result depends on besides the file: what the caller gives, and the packages of
	 * the project, never those of the running process, which are the tool's own wherever it is not installed in
	 * the project.
	 * @param  array<mixed>  $configuration
	 */
	private static function hashConfiguration(array $configuration, ProjectPackages $project): string
	{
		return hash('xxh128', json_encode([$configuration, $project->getIdentity()], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR));
	}


	/**
	 * Where the packages of the running process lie, which is what tells a file of a rule the run builds from
	 * a package apart from a file of the project itself: InstalledVersions answers from every registered loader,
	 * the one inside the phar of PHPStan included once it is started, so a root of a phar is left out. The path
	 * of the root package is null, its files being the ones the caller weighs.
	 * @return array<string, ?string>  package → where it lies
	 */
	private static function getProcessPackagePaths(): array
	{
		if (!class_exists(InstalledVersions::class)) {
			return [];
		}

		$packages = [];
		foreach (InstalledVersions::getAllRawData() as $data) {
			if (str_starts_with($data['root']['install_path'], 'phar://')) {
				continue;
			}

			foreach ($data['versions'] as $name => $package) {
				$packages[$name] ??= $name === $data['root']['name'] ? null : ($package['install_path'] ?? null);
			}
		}

		return $packages;
	}


	/**
	 * The modification times of the files the rules, presets and analyses of the run are declared in, their parents
	 * and traits included, where neither a package of the running process nor a phar holds them: the version of
	 * a package stands for its files, while a rule of the project itself changes under the same version of the
	 * project. A process without Composer knows no package, so every file outside a phar counts.
	 * @param  array<class-string, ?\Closure(FileNode, string): object>  $analyses
	 * @return array<string, int|false>  file → modification time
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
			...array_values($this->registry->getRules()),
			...array_map(fn(ResolvedRule $rule) => $rule->class, $resolved->rules),
			...array_values($this->registry->getPresets()),
			...array_keys($analyses),
		];
		$times = [];
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
	private static function hasClosures(Config $config, ResolvedConfig $resolved, array $analyses): bool
	{
		return array_any($resolved->rules, fn(ResolvedRule $rule) => $rule->factory !== null)
			|| array_any($config->overrides, fn(Override $override) => array_any($override->rules, fn($value) => $value instanceof \Closure))
			|| array_filter($analyses) !== [];
	}


	/** The configured baseline when its file exists; before the first generation there is none. */
	public static function loadBaseline(Config $config, string $root): ?Baseline
	{
		return $config->baseline === null ? null : Baseline::load(self::toAbsolutePath($config->baseline, $root));
	}


	/** A path of the configuration as the filesystem takes it: an absolute one stands, a relative one is under the root. */
	public static function toAbsolutePath(string $path, string $root): string
	{
		return FileSystem::isAbsolute($path)
			? $path
			: Helpers::canonicalizePath($root) . '/' . $path;
	}


	/**
	 * The version the code is written for unless an override says another: the configured one, or the lowest one the
	 * nearest composer.json allows, or the default one. The running version is never the answer: it says nothing about
	 * the code being checked.
	 * @return array{string, PhpVersionSource}
	 */
	public function resolvePhpVersion(Config $config, string $root): array
	{
		$detected = $config->php === null ? self::detectPhpVersion(self::findComposerFile($root)) : null;
		return match (true) {
			$config->php !== null => [$config->php, PhpVersionSource::Configuration],
			$detected !== null => [$detected, PhpVersionSource::Composer],
			default => [Config::DefaultPhpVersion, PhpVersionSource::Default],
		};
	}


	/**
	 * The composer.json of the root or of a directory above it, the way the configuration file is looked up.
	 */
	public static function findComposerFile(string $root): ?string
	{
		$directory = Helpers::canonicalizePath($root);
		while (true) {
			if (is_file("$directory/composer.json")) {
				return "$directory/composer.json";
			}

			$parent = dirname($directory);
			if ($parent === $directory) {
				return null;
			}

			$directory = $parent;
		}
	}


	/**
	 * The lowest version the constraint of require.php allows, as major.minor; null where the constraint has no lower
	 * bound (`<8.4`, `*`) or is no constraint, which leaves the version to the default rather than to a guess.
	 */
	public static function detectPhpVersion(?string $composerFile): ?string
	{
		$constraint = self::readComposer($composerFile)['require']['php'] ?? null;
		$version = is_string($constraint) ? ProjectPackages::findLowestVersion($constraint) : null;
		return $version === null ? null : implode('.', array_slice([...explode('.', $version), '0'], 0, 2));
	}


	/**
	 * The directories autoload and autoload-dev name, in the order of the file, relative to the root and with
	 * their dot segments resolved: the only place where a project itself says where its code is. A `files`
	 * entry is a single file, not a scope, and a classmap may name one too, so only what is a directory
	 * counts; a directory outside the root belongs to another project, since the file may be the one of a
	 * directory above.
	 * @return list<string>
	 */
	public static function detectAutoloadPaths(?string $composerFile, string $root): array
	{
		$data = self::readComposer($composerFile);
		if ($data === null) {
			return [];
		}

		$base = Helpers::canonicalizePath(dirname((string) $composerFile));
		$root = Helpers::canonicalizePath($root);
		$paths = [];
		foreach (['autoload', 'autoload-dev'] as $section) {
			foreach (['psr-4', 'psr-0', 'classmap'] as $kind) {
				foreach ((array) ($data[$section][$kind] ?? []) as $value) {
					foreach ((array) $value as $path) { // a psr-4 prefix takes one path or several
						// `./src` and `src` are the same path, and only resolved do they compare as one
						$directory = is_string($path) ? Helpers::canonicalizePath(FileSystem::normalizePath("$base/$path")) : null;
						if ($directory === null || !is_dir($directory)) {
							continue;
						} elseif ($directory === $root) {
							$paths['.'] = true;
						} elseif (str_starts_with($directory, "$root/")) {
							$paths[substr($directory, strlen($root) + 1)] = true;
						}
					}
				}
			}
		}

		return array_keys($paths);
	}


	/**
	 * What the composer.json holds; null when there is none, it cannot be read or it is not an object.
	 * @return ?array<mixed>
	 */
	private static function readComposer(?string $composerFile): ?array
	{
		$json = $composerFile === null ? false : @file_get_contents($composerFile); // @ - the file is optional
		$data = $json === false ? null : json_decode($json, associative: true);
		return is_array($data) ? $data : null;
	}


	public function getRegistry(): RuleRegistry
	{
		return $this->registry;
	}
}
