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
use DressCode\Runner;
use PhpSyntax\Style;
use function is_array, is_string;


/**
 * Builds the runner of a run from a configuration.
 * @internal
 */
final class RunnerFactory
{
	public function __construct(
		private readonly RuleRegistry $registry = new RuleRegistry,
	) {
	}


	/**
	 * @param bool $strict  a broken rule contract throws instead of warning
	 * @param bool $cache  clean files are remembered and skipped next time
	 * @throws ConfigurationException
	 */
	public function createRunner(Config $config, string $root, bool $strict = false, bool $cache = true): Runner
	{
		$phpVersion = $this->resolvePhpVersion($config, $root);
		$resolver = new PresetResolver($this->registry);
		$context = new PresetContext($phpVersion);
		$rules = $resolver->resolve($config, $context);
		$analyses = new Analyses\Registry;
		foreach ($config->getAnalyses() as $class => $factory) {
			$analyses->register($class, $factory);
		}

		[$indent, $eol] = $resolver->resolveStyle($config);
		$resultCache = $cache
			? ResultCache::load(
				self::resolveCacheFile($config, $root),
				self::hashConfiguration([$resolver->describe($config, $context), $phpVersion, $indent, $eol, $config->getAnalyses() === [] ? [] : array_keys($config->getAnalyses())]),
			)
			: null;
		$processor = new FileProcessor(
			$rules,
			$analyses,
			$this->registry->resolveNames(...),
			$phpVersion,
			new Style($indent, $eol === 'majority' ? "\n" : $eol),
			detectEol: $eol === 'majority',
			strict: $strict,
		);
		return new Runner(
			$processor,
			$root,
			$config->getExcludePaths(),
			$config->getRuleExcludePaths(),
			$config->getFileExtensions(),
			$config->getSkipWhen(),
			self::loadBaseline($config, $root),
			$resultCache,
		);
	}


	/** The cache file of the project root: in the configured directory, else in the system temp. */
	public static function resolveCacheFile(Config $config, string $root): string
	{
		$root = Helpers::canonicalizePath($root);
		$dir = $config->getCacheDir();
		$dir = match (true) {
			$dir === null => sys_get_temp_dir() . '/dresscode',
			(bool) preg_match('~^(?:[A-Za-z]:)?[/\\\]~', $dir) => $dir,
			default => "$root/$dir",
		};
		return rtrim(str_replace('\\', '/', $dir), '/') . '/' . substr(hash('xxh128', $root), 0, 16) . '.json';
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
		return $file === null || preg_match('~^(?:[A-Za-z]:)?[/\\\]~', $file)
			? $file
			: rtrim(str_replace('\\', '/', $root), '/') . '/' . $file;
	}


	/**
	 * The configured version, or the lowest one composer.json of the root allows, or the running one.
	 */
	public function resolvePhpVersion(Config $config, string $root): string
	{
		$version = $config->getPhpVersion();
		return $version !== 'auto'
			? $version
			: (self::detectPhpVersion("$root/composer.json") ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
	}


	public static function detectPhpVersion(string $composerFile): ?string
	{
		$json = @file_get_contents($composerFile); // @ - the file is optional
		$data = $json === false ? null : json_decode($json, associative: true);
		$constraint = is_array($data) ? ($data['require']['php'] ?? null) : null;
		return is_string($constraint) && preg_match('~\d+\.\d+~', $constraint, $m)
			? $m[0]
			: null;
	}


	public function getRegistry(): RuleRegistry
	{
		return $this->registry;
	}
}
