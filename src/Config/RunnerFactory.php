<?php declare(strict_types=1);

namespace DressCode\Config;

use Composer\InstalledVersions;
use DressCode\Analyses;
use DressCode\Config;
use DressCode\ConfigurationException;
use DressCode\Engine\Baseline;
use DressCode\Engine\FileProcessor;
use DressCode\Engine\ResultCache;
use DressCode\Helpers;
use DressCode\PresetContext;
use DressCode\RuleInfo;
use DressCode\Runner;
use Nette\Utils\FileSystem;
use PhpSyntax\Style;
use function is_array, is_string, strlen;


/**
 * Builds the runner of a run from a configuration.
 * @internal
 */
final class RunnerFactory
{
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
	 * @param bool $strict  a broken rule contract throws instead of warning
	 * @param bool $cache  clean files are remembered and skipped next time
	 * @throws ConfigurationException
	 */
	public function createRunner(Config $config, string $root, bool $strict = false, bool $cache = true): Runner
	{
		$config = $config->resolveExtensions();
		foreach ($config->getRegisteredRules() as $class) {
			$this->registry->registerRule($class);
		}

		foreach ($config->getRegisteredPresets() as $class) {
			$this->registry->registerPreset($class);
		}

		$this->checkRulesOfBlocks($config);
		[$phpVersion] = $this->phpVersion = $this->resolvePhpVersion($config, $root);
		$resolver = new PresetResolver($this->registry);
		$context = new PresetContext($phpVersion);
		$this->resolved = $resolved = $resolver->resolveConfig($config, $context);
		$this->warnings = $resolver->getWarnings();
		$analyses = new Analyses\Registry;
		foreach ($config->getAnalyses() as $class => $factory) {
			$analyses->register($class, $factory);
		}

		$baseline = self::loadBaseline($config, $root);
		$warningRules = $this->resolveWarnings($config);
		$fixRisky = $config->getRisky();
		$this->resolveFor = fn(array $blocks) => $blocks === []
			? $resolved
			: $resolver->resolveConfig($config, $context, array_values($blocks));
		$processors = new FileProcessors(
			array_map(fn(array $block) => $block[0], $config->getBlocks()),
			function (array $blocks) use ($analyses, $phpVersion, $strict, $baseline, $warningRules, $fixRisky, $resolver): FileProcessor {
				$variant = $this->resolveConfigFor($blocks);
				return new FileProcessor(
					$resolver->build($variant),
					$analyses,
					$this->registry->resolveNames(...),
					$phpVersion,
					new Style($variant->indent, $variant->eol === 'majority' ? "\n" : $variant->eol),
					detectEol: $variant->eol === 'majority',
					strict: $strict,
					baseline: $baseline,
					warningRules: $warningRules,
					fixRisky: $fixRisky,
				);
			},
		);
		$resultCache = $cache
			? ResultCache::load(
				self::resolveCacheFile($config, $root),
				// the baseline decides what a rule reports, so a file clean under one is not clean under another
				self::hashConfiguration([$resolved->toArray(), $config->getAnalyses() === [] ? [] : array_keys($config->getAnalyses()), $baseline?->getHash(), $config->getBlocks(), $fixRisky]),
			)
			: null;
		return new Runner(
			$processors,
			$root,
			$config->getExcludePaths(),
			$config->getFileExtensions(),
			$config->getSkipWhen(),
			$baseline,
			$resultCache,
		);
	}


	/**
	 * What the configuration comes to for a file matching those blocks; the same resolution the run uses.
	 * @param  list<int>  $blocks
	 */
	public function resolveConfigFor(array $blocks): ResolvedConfig
	{
		return ($this->resolveFor ?? throw new \LogicException('No engine has been built yet.'))($blocks);
	}


	/** The cache file of the project root: in the configured directory, else in the system temp. */
	public static function resolveCacheFile(Config $config, string $root): string
	{
		$root = Helpers::canonicalizePath($root);
		$dir = $config->getCacheDir();
		$dir = $dir === null ? sys_get_temp_dir() . '/dresscode' : self::toAbsolutePath($dir, $root);
		return Helpers::canonicalizePath($dir) . '/' . substr(hash('xxh128', $root), 0, 16) . '.json';
	}


	/**
	 * Identity of everything a result depends on besides the file: the effective rules with their options, the
	 * style, the PHP version and the versions (with their git references) of every installed package.
	 * @param  array<mixed>  $configuration
	 */
	public static function hashConfiguration(array $configuration): string
	{
		$packages = [];
		if (class_exists(InstalledVersions::class)) {
			foreach (InstalledVersions::getInstalledPackages() as $package) {
				$packages[$package] = [InstalledVersions::getVersion($package), InstalledVersions::getReference($package)];
			}
		}

		return hash('xxh128', json_encode([$configuration, $packages], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR));
	}


	/**
	 * A block is resolved only for a file it matches, so a name no rule owns would otherwise pass unnoticed
	 * until such a file comes; it is an error of the configuration as soon as the run is built.
	 * @throws ConfigurationException
	 */
	private function checkRulesOfBlocks(Config $config): void
	{
		foreach ($config->getBlocks() as [, $rules]) {
			foreach (array_keys($rules) as $rule) {
				$this->registry->resolveRule($rule);
			}
		}
	}


	/**
	 * The rules that only warn, under the names the engine asks by, so that a class stands for its rule and
	 * a name no rule owns is an error instead of a line that quietly does nothing.
	 * @return array<string, true>
	 * @throws ConfigurationException
	 */
	private function resolveWarnings(Config $config): array
	{
		$resolved = [];
		foreach ($config->getWarnings() as $rule) {
			$resolved[RuleInfo::of($this->registry->resolveRule($rule))->name] = true;
		}

		return $resolved;
	}


	/** The configured baseline when its file exists; before the first generation there is none. */
	public static function loadBaseline(Config $config, string $root): ?Baseline
	{
		$file = self::resolveBaselineFile($config, $root);
		return $file === null ? null : Baseline::load($file);
	}


	/** Absolute path of the configured baseline file, relative paths under the root. */
	public static function resolveBaselineFile(Config $config, string $root): ?string
	{
		$file = $config->getBaseline();
		return $file === null ? null : self::toAbsolutePath($file, $root);
	}


	/** A path of the configuration as the filesystem takes it: an absolute one stands, a relative one is under the root. */
	public static function toAbsolutePath(string $path, string $root): string
	{
		return FileSystem::isAbsolute($path)
			? $path
			: Helpers::canonicalizePath($root) . '/' . $path;
	}


	/**
	 * The configured version, or the lowest one the nearest composer.json allows, or the default one. The
	 * running version is never the answer: it says nothing about the code being checked.
	 * @return array{string, PhpVersionSource}
	 */
	public function resolvePhpVersion(Config $config, string $root): array
	{
		$version = $config->getPhp();
		if ($version !== 'auto') {
			return [$version, PhpVersionSource::Configuration];
		}

		$detected = self::detectPhpVersion(self::findComposerFile($root));
		return $detected === null
			? [Config::DefaultPhpVersion, PhpVersionSource::Default]
			: [$detected, PhpVersionSource::Composer];
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


	/** The lowest version the constraint of require.php allows; a constraint naming no number has none. */
	public static function detectPhpVersion(?string $composerFile): ?string
	{
		$constraint = self::readComposer($composerFile)['require']['php'] ?? null;
		return is_string($constraint) && preg_match('~(\d+)(?:\.(\d+))?~', $constraint, $m)
			? $m[1] . '.' . ($m[2] ?? '0')
			: null;
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
