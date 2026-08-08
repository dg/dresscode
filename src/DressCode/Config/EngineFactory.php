<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\AnalysisRegistry;
use DressCode\Config;
use DressCode\ConfigurationException;
use DressCode\Engine;
use DressCode\Engine\Baseline;
use DressCode\Engine\FileProcessor;
use DressCode\PresetContext;
use PhpSyntax\PhpVersion;
use PhpSyntax\Style;


/**
 * Builds the engine of a run from a configuration.
 * @internal
 */
final class EngineFactory
{
	public function __construct(
		private readonly RuleRegistry $registry = new RuleRegistry,
	) {
	}


	/**
	 * @param bool $strict  a broken rule contract throws instead of warning
	 * @throws ConfigurationException
	 */
	public function createEngine(Config $config, string $root, bool $strict = false): Engine
	{
		$phpVersion = $this->resolvePhpVersion($config, $root);
		$resolver = new PresetResolver($this->registry);
		$rules = $resolver->resolve($config, new PresetContext($phpVersion));
		$analyses = new AnalysisRegistry;
		foreach ($config->getAnalyses() as $class => $factory) {
			$analyses->register($class, $factory);
		}

		[$indent, $eol] = $resolver->resolveStyle($config);
		$processor = new FileProcessor(
			$rules,
			$analyses,
			$this->registry->resolveName(...),
			new Style($indent, $eol === 'auto' ? "\n" : $eol),
			detectEol: $eol === 'auto',
			phpVersion: $phpVersion,
			strict: $strict,
		);
		return new Engine(
			$processor,
			$root,
			$config->getSkip(),
			$config->getRuleSkip(),
			$config->getFileExtensions(),
			$config->getSkipWhen(),
			self::loadBaseline($config, $root),
		);
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
		return $file === null || preg_match('~^(?:[A-Za-z]:)?[/\\\]~', $file)
			? $file
			: rtrim(str_replace('\\', '/', $root), '/') . '/' . $file;
	}


	/**
	 * The configured version, or the lowest one composer.json of the root allows, or the running one.
	 */
	public function resolvePhpVersion(Config $config, string $root): PhpVersion
	{
		$version = $config->getPhpVersion();
		return $version instanceof PhpVersion
			? $version
			: (self::detectPhpVersion("$root/composer.json") ?? PhpVersion::current());
	}


	public static function detectPhpVersion(string $composerFile): ?PhpVersion
	{
		$json = @file_get_contents($composerFile); // @ - the file is optional
		$data = $json === false ? null : json_decode($json, associative: true);
		$constraint = is_array($data) ? ($data['require']['php'] ?? null) : null;
		return is_string($constraint) && preg_match('~\d+\.\d+~', $constraint, $m)
			? PhpVersion::fromString($m[0])
			: null;
	}


	public function getRegistry(): RuleRegistry
	{
		return $this->registry;
	}
}
