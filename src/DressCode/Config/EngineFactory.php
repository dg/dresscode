<?php declare(strict_types=1);

namespace DressCode\Config;

use Composer\InstalledVersions;
use DressCode\AnalysisRegistry;
use DressCode\Config;
use DressCode\ConfigurationException;
use DressCode\Engine;
use DressCode\Engine\Baseline;
use DressCode\Engine\FileProcessor;
use DressCode\Engine\ResultCache;
use DressCode\PresetContext;
use DressCode\RuleInfo;
use PhpSyntax\PhpVersion;
use PhpSyntax\Style;


/**
 * Builds the engine of a run from a configuration.
 * @internal
 */
final class EngineFactory
{
	/** @var list<string> */
	private array $warnings = [];

	/** @var ?array{PhpVersion, PhpVersionSource} */
	private ?array $phpVersion = null;


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
	 * @return array{PhpVersion, PhpVersionSource}
	 */
	public function getPhpVersion(): array
	{
		return $this->phpVersion ?? throw new \LogicException('No engine has been built yet.');
	}


	/**
	 * @param bool $strict  a broken rule contract throws instead of warning
	 * @param bool $cache  clean files are remembered and skipped next time
	 * @throws ConfigurationException
	 */
	public function createEngine(Config $config, string $root, bool $strict = false, bool $cache = true): Engine
	{
		$config = $config->resolveExtensions();
		foreach ($config->getRegisteredRules() as $class) {
			$this->registry->registerRule($class);
		}

		foreach ($config->getRegisteredPresets() as $class) {
			$this->registry->registerPreset($class);
		}

		$ruleExcludePaths = $this->resolveRuleExcludePaths($config);
		[$phpVersion] = $this->phpVersion = $this->resolvePhpVersion($config, $root);
		$resolver = new PresetResolver($this->registry);
		$context = new PresetContext($phpVersion);
		$rules = $resolver->resolve($config, $context);
		$this->warnings = $resolver->getWarnings();
		$analyses = new AnalysisRegistry;
		foreach ($config->getAnalyses() as $class => $factory) {
			$analyses->register($class, $factory);
		}

		[$indent, $eol] = $resolver->resolveStyle($config);
		$resultCache = $cache
			? ResultCache::load(
				self::resolveCacheFile($config, $root),
				self::hashConfiguration([$resolver->describe($config, $context), (string) $phpVersion, $indent, $eol, $config->getAnalyses() === [] ? [] : array_keys($config->getAnalyses()), $ruleExcludePaths]),
			)
			: null;
		$processor = new FileProcessor(
			$rules,
			$analyses,
			$this->registry->resolveNames(...),
			$phpVersion,
			new Style($indent, $eol === 'auto' ? "\n" : $eol),
			detectEol: $eol === 'auto',
			strict: $strict,
		);
		return new Engine(
			$processor,
			$root,
			$config->getExcludePaths(),
			$ruleExcludePaths,
			$config->getFileExtensions(),
			$config->getSkipWhen(),
			self::loadBaseline($config, $root),
			$resultCache,
		);
	}


	/** The cache file of the project root: in the configured directory, else in the system temp. */
	public static function resolveCacheFile(Config $config, string $root): string
	{
		$root = rtrim(str_replace('\\', '/', $root), '/');
		$dir = $config->getCacheDir();
		$dir = $dir === null ? sys_get_temp_dir() . '/dresscode' : self::toAbsolutePath($dir, $root);
		return rtrim(str_replace('\\', '/', $dir), '/') . '/' . substr(hash('xxh128', $root), 0, 16) . '.json';
	}


	/**
	 * Identity of everything a result depends on besides the file: the effective rules with their options and
	 * the paths they are left out of, the style, the PHP version and the versions (with their git references)
	 * of every installed package.
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
	 * The excluded paths under the name of the rule the engine will ask by, so that a class stands for its
	 * rule here as it does everywhere else and a name no rule owns is an error instead of a silent no-op.
	 * @return array<string, list<string>>
	 * @throws ConfigurationException
	 */
	private function resolveRuleExcludePaths(Config $config): array
	{
		$resolved = [];
		foreach ($config->getRuleExcludePaths() as $rule => $patterns) {
			$name = RuleInfo::of($this->registry->resolveRule($rule))->name;
			$resolved[$name] = [...$resolved[$name] ?? [], ...$patterns];
		}

		return $resolved;
	}


	/** The configured baseline when its file exists; before the first generation there is none. */
	public static function loadBaseline(Config $config, string $root): ?Baseline
	{
		$file = self::resolveBaselineFile($config, $root);
		return $file !== null && is_file($file) ? Baseline::load($file) : null;
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
		return preg_match('~^(?:[A-Za-z]:)?[/\\\]~', $path)
			? $path
			: rtrim(str_replace('\\', '/', $root), '/') . '/' . $path;
	}


	/**
	 * The configured version, or the lowest one the nearest composer.json allows, or the default one. The
	 * running version is never the answer: it says nothing about the code being checked.
	 * @return array{PhpVersion, PhpVersionSource}
	 */
	public function resolvePhpVersion(Config $config, string $root): array
	{
		$version = $config->getPhpVersion();
		if ($version instanceof PhpVersion) {
			return [$version, PhpVersionSource::Configuration];
		}

		$detected = self::detectPhpVersion(self::findComposerFile($root));
		return $detected === null
			? [PhpVersion::lowest(), PhpVersionSource::Default]
			: [$detected, PhpVersionSource::Composer];
	}


	/**
	 * The composer.json of the root or of a directory above it, the way the configuration file is looked up.
	 */
	public static function findComposerFile(string $root): ?string
	{
		$directory = rtrim(str_replace('\\', '/', $root), '/');
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
	public static function detectPhpVersion(?string $composerFile): ?PhpVersion
	{
		$json = $composerFile === null ? false : @file_get_contents($composerFile); // @ - the file is optional
		$data = $json === false ? null : json_decode($json, associative: true);
		$constraint = is_array($data) ? ($data['require']['php'] ?? null) : null;
		return is_string($constraint) && preg_match('~(\d+)(?:\.(\d+))?~', $constraint, $m)
			? new PhpVersion((int) $m[1], (int) ($m[2] ?? 0))
			: null;
	}


	public function getRegistry(): RuleRegistry
	{
		return $this->registry;
	}
}
