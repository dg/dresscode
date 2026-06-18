<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Analyses, Config, ConfigurationException, Override, Plugin, PluginManifest, Preset, Profile, Rule, Style};
use DressCode\Engine\{FileProcessor, Helpers, Runner};
use Nette\Utils\FileSystem;
use function count, dirname, is_array, is_string;


/**
 * Builds the runner of a run from a configuration, and reads what the composer.json of the project says of it.
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
		public readonly RuleRegistry $registry = new RuleRegistry,
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
	 * @param  bool  $fixRisky  every fix that may change what the code does is made, not only those of the rules
	 *                           the configuration names in fixRisky
	 * @throws ConfigurationException
	 */
	public function createRunner(
		Config $config,
		string $root,
		?Profile $commandLine = null,
		?array $only = null,
		bool $strict = false,
		bool $fixRisky = false,
	): Runner
	{
		$visited = [];
		$layers = [...$this->loadPlugins($config->plugins, $visited), $config];
		$this->registerNamedClasses($config);
		[$target, $source] = $this->resolvePhpTarget($config, $root);
		$resolver = new ConfigResolver($this->registry);
		$this->resolved = $resolved = $resolver->resolve($config, $target, [], $commandLine, $only);
		// an override is resolved for a file it matches, so a name or an option it gets wrong would pass unnoticed until
		// such a file comes; each of them is resolved as soon as the run is built
		foreach (array_keys($config->overrides) as $index) {
			$resolver->resolve($config, $target, [$index], $commandLine, $only);
		}

		$this->warnings = $resolver->getWarnings();
		$this->phpVersion = [$resolved->phpVersion, $source];
		$analyses = array_merge(...array_map(fn(Config|PluginManifest $layer) => $layer->analyses, $layers));

		// the processors are built lazily, so they must not ask the factory, which may have built another runner since
		$this->resolveFor = $resolveFor = fn(array $overrides) => $overrides === []
			? $resolved
			: $resolver->resolve($config, $target, array_values($overrides), $commandLine, $only);
		$registry = $this->registry;
		$processors = new FileProcessors(
			array_map(fn(Override $override) => $override->paths, $config->overrides),
			function (array $overrides) use ($resolveFor, $registry, $analyses, $strict, $fixRisky): FileProcessor {
				$variant = $resolveFor($overrides);
				$analysisRegistry = new Analyses\Registry($variant->toNamespacedSymbols());
				foreach ($analyses as $class => $factory) {
					$analysisRegistry->register($class, $factory);
				}

				$warningRules = $fixRiskyRules = [];
				foreach ($variant->getActiveRules() as $rule) {
					if ($rule->warnOnly) {
						$warningRules[$rule->name] = true;
					}

					if ($rule->fixRisky) {
						$fixRiskyRules[$rule->name] = true;
					}
				}

				return new FileProcessor(
					RuleBuilder::buildRules($variant),
					$analysisRegistry,
					$registry->resolveNames(...),
					$variant->phpVersion,
					new Style($variant->indent, $variant->lineEnding === 'majority' ? "\n" : $variant->lineEnding, lineLength: $variant->lineLength),
					detectLineEnding: $variant->lineEnding === 'majority',
					strict: $strict,
					warningRules: $warningRules,
					fixRisky: $fixRisky,
					fixRiskyRules: $fixRiskyRules,
				);
			},
		);
		return new Runner(
			$processors,
			$root,
			array_values(array_unique(array_merge(...array_map(fn(Config|PluginManifest $layer) => $layer->excludePaths, $layers)))),
			$config->fileExtensions,
			self::combineSkipWhen($layers),
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
	 * Makes the rules and presets the plugins bring known and returns what they bring, each class once and the plugins
	 * a plugin builds on before it.
	 * @param  list<class-string<Plugin>|Plugin>  $plugins
	 * @param  array<string, true>  $visited
	 * @return list<PluginManifest>
	 * @throws ConfigurationException
	 */
	private function loadPlugins(array $plugins, array &$visited): array
	{
		$manifests = [];
		foreach ($plugins as $plugin) {
			if (is_string($plugin)) {
				if (isset($visited[$plugin])) {
					continue;
				}

				$visited[$plugin] = true;
				$plugin = new $plugin;
			}

			$manifest = $plugin->getManifest();
			foreach ($manifest->rules as $rule) {
				$this->registry->registerRule($rule, $manifest->ruleUrl);
			}

			foreach ($manifest->presets as $preset) {
				$this->registry->registerPreset($preset);
			}

			$manifests = [...$manifests, ...$this->loadPlugins($manifest->plugins, $visited), $manifest];
		}

		return $manifests;
	}


	/**
	 * Makes the rules and presets the configuration names by class known under their names, the rules with the address
	 * of their page the configuration gives.
	 * @throws ConfigurationException
	 */
	private function registerNamedClasses(Config $config): void
	{
		foreach ([$config, ...$config->overrides] as $profile) {
			foreach ([...array_keys($profile->rules), ...$profile->fixRisky, ...$profile->warnOnly] as $name) {
				if (class_exists($name) && is_subclass_of($name, Rule::class)) {
					$this->registry->registerRule($name, $config->ruleUrl);
				}
			}

			foreach ($profile->presets as $name) {
				if (class_exists($name) && is_subclass_of($name, Preset::class)) {
					$this->registry->registerPreset($name);
				}
			}
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


	/** A path of the configuration as the filesystem takes it: an absolute one stands, a relative one is under the root. */
	public static function toAbsolutePath(string $path, string $root): string
	{
		return FileSystem::isAbsolute($path)
			? $path
			: Helpers::canonicalizePath($root) . '/' . $path;
	}


	/**
	 * The versions the code is written for unless an override says another, as a Composer constraint: the configured
	 * one, or those the nearest composer.json allows, or the default one. The running version is never the answer: it
	 * says nothing about the code being checked.
	 * @return array{string, PhpVersionSource}
	 */
	public function resolvePhpTarget(Config $config, string $root): array
	{
		$php = $config->targets['php'] ?? null;
		$detected = $php === null ? self::detectPhpTarget(self::findComposerFile($root)) : null;
		return match (true) {
			$php !== null => [$php, PhpVersionSource::Configuration],
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
	 * The constraint of require.php; null where it has no lower bound (`<8.4`, `*`) or is no constraint, which leaves
	 * the version to the default rather than to a guess.
	 */
	public static function detectPhpTarget(?string $composerFile): ?string
	{
		$constraint = self::readComposer($composerFile)['require']['php'] ?? null;
		return is_string($constraint) && Versions::findLowestVersion($constraint) !== null ? $constraint : null;
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
}
