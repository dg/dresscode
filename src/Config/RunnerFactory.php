<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException, Override, Plugin, PluginManifest, Profile};
use DressCode\Engine\{FileProcessor, FileProcessors, Helpers, ReportPolicy, Runner};
use Nette\Utils\FileSystem;
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
		$visited = [];
		$plugins = [
			...$this->loadPlugins($config->plugins, $visited),
			...$this->loadPlugins($commandLine instanceof Config ? $commandLine->plugins : [], $visited),
		];
		$this->registerProjectRules($config);
		[$target, $source] = $this->getPhpTarget($config, $root);
		$resolver = new ConfigResolver($this->registry, $root);
		$resolved = $resolver->resolve($config, $target, [], $commandLine, $only);
		$warnings = array_fill_keys($resolver->getWarnings(), null);

		return new ResolvedProject(
			$config,
			$root,
			$resolved,
			$resolved->phpVersion,
			$source,
			$warnings,
			$plugins,
			$commandLine,
			$only,
			$resolver,
			$target,
		);
	}


	/**
	 * @param  bool  $strict  a broken rule contract throws instead of warning
	 * @param  bool  $fixRisky  every fix that may change what the code does is made, not only those of the decisions
	 *                           the configuration names in fixRisky
	 * @throws ConfigurationException
	 */
	public function createRunner(
		ResolvedProject $resolution,
		bool $strict = false,
		bool $fixRisky = false,
	): Runner
	{
		$config = $resolution->config;
		$root = $resolution->root;
		$layers = [...$resolution->pluginManifests, $config];
		$analyses = array_merge(...array_map(fn(Config|PluginManifest $layer) => $layer->analyses, $layers));

		$registry = $this->registry;
		$processors = new FileProcessors(
			array_map(fn(Override $override) => $override->paths, $config->overrides),
			function (array $overrides) use ($resolution, $registry, $analyses, $strict, $fixRisky): FileProcessor {
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
					detectLineEnding: $variant->lineEnding === 'majority',
					policy: new ReportPolicy(
						expandName: $registry->expandSuppressedName(...),
						warnOnly: $variant->warnOnly,
						fixRisky: $fixRisky ?: $variant->fixRisky,
						strict: $strict,
					),
					gates: $variant->getGates(),
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
