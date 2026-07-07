<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\AnalysisRegistry;
use DressCode\Config;
use DressCode\ConfigurationException;
use DressCode\Engine;
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
		$rules = (new PresetResolver($this->registry))->resolve($config, new PresetContext($phpVersion));
		$analyses = new AnalysisRegistry;
		foreach ($config->getAnalyses() as $class => $factory) {
			$analyses->register($class, $factory);
		}

		$eol = $config->getEol();
		$processor = new FileProcessor(
			$rules,
			$analyses,
			$this->registry->resolveName(...),
			new Style($config->getIndent(), $eol === 'auto' ? "\n" : $eol),
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
		);
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
