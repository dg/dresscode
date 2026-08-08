<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Analyses;
use DressCode\Config;
use DressCode\ConfigurationException;
use DressCode\Engine\FileProcessor;
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
	 * @throws ConfigurationException
	 */
	public function createRunner(Config $config, string $root, bool $strict = false): Runner
	{
		$phpVersion = $this->resolvePhpVersion($config, $root);
		$resolver = new PresetResolver($this->registry);
		$rules = $resolver->resolve($config, new PresetContext($phpVersion));
		$analyses = new Analyses\Registry;
		foreach ($config->getAnalyses() as $class => $factory) {
			$analyses->register($class, $factory);
		}

		[$indent, $eol] = $resolver->resolveStyle($config);
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
		);
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
