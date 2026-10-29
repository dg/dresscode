<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\{Config, ConfigurationException};
use DressCode\Config\{PackageDiscovery, ProjectPackages, RunnerFactory};
use DressCode\Engine\Helpers;
use DressCode\Measuring\{NamespaceDeclarations, Scope};
use DressCode\Reporters\CollectingReporter;
use function count;


/**
 * The project an upgrade measures, plans or runs: its configuration, or the scope the command gives it where it has no
 * file of its own, and what the command line lays over it.
 * @internal
 */
final readonly class Project
{
	public function __construct(
		public Config $config,
		public string $root,
		/** the configuration file, whose style a rewrite keeps; null for a project without one */
		public ?string $configFile = null,
		/** laid over the configuration, as `--use` and `--set` are */
		public ?Config $commandLine = null,
	) {
	}


	/**
	 * The project of an upgrade over its configuration, or over the scope `init` would write where it has no file of its
	 * own, so that every command of an upgrade sees the same files.
	 * @throws ConfigurationException  for a directory that is no project at all
	 */
	public static function fromConfig(Config $config, string $root, ?string $configFile, ?Config $commandLine = null): self
	{
		if ($configFile === null) {
			if (ProjectPackages::read($root)->rootPath === null) {
				throw new ConfigurationException('There is nothing to upgrade: the directory has no configuration of DressCode and no `composer.json`.');
			}

			$scope = Scope::fromProject($root, null);
			$config = new Config(paths: $scope->listPaths(), excludePaths: array_keys($scope->listExcludePaths()));
		}

		return new self($config, $root, $configFile, $commandLine);
	}


	/**
	 * The packages of upgrading data that reach the project, with the versions of the targets where given.
	 * @param  array<string, string>  $targets  package => version
	 */
	public function discoverPackages(array $targets = []): PackageDiscovery
	{
		return PackageDiscovery::discover(
			new RunnerFactory()->readProjectPackages($this->config, $this->root, commandLine: new Config(targets: $targets)),
		);
	}


	/**
	 * The layer an upgrade lays over the configuration: the command line, the plugins of the packages of upgrading data,
	 * the targets, the types, and where they were measured in every file the names resolved for certain with what the
	 * namespaces declare.
	 * @param  list<string>  $plugins
	 * @param  array<string, string>  $targets  package => version
	 * @param  array<string, mixed>  $decisions  path => value, over those of the command line
	 */
	public function createLayer(
		array $plugins,
		bool $types,
		array $targets = [],
		?NamespaceDeclarations $declarations = null,
		array $decisions = [],
	): Config
	{
		$commandLine = $this->commandLine;
		$certain = $declarations !== null;
		$tree = $commandLine->decisions ?? [];
		foreach ($decisions as $path => $value) {
			$tree = Helpers::placeValue($tree, $path, $value);
		}

		return new Config(
			use: [...$commandLine->plugins ?? [], ...$commandLine->use ?? [], ...$plugins],
			targets: $targets,
			namespaces: $certain ? ['functions' => $declarations->functions, 'constants' => $declarations->constants] : [],
			nameResolution: $certain ? 'certain' : null,
			fixRisky: $commandLine->fixRisky ?? [],
			severity: $commandLine->severity ?? [],
			typeAnalysis: $types ? 'phpstan' : null,
			decisions: $tree,
		);
	}


	/**
	 * How the names are resolved where the configuration says nothing of them: for certain with what the namespaces of
	 * the scope declare, or of the files of it given, unless a file cannot be read or does not parse.
	 * @param  ?list<string>  $files  relative to the root
	 */
	public function resolveNames(?array $files = null): NameResolution
	{
		if ($this->config->nameResolution !== null) {
			return new NameResolution;
		}

		$declarations = NamespaceDeclarations::collect($this->root, $files ?? $this->findFiles());
		return $declarations->unparsed === []
			? new NameResolution($declarations)
			: new NameResolution(warning: 'The names are not resolved for certain: ' . count($declarations->unparsed) . ' of the files cannot be read or do not parse.');
	}


	/**
	 * The files of the scope of the configuration, or of the paths within it, relative to the root.
	 * @param  list<string>  $paths  all the configured ones where empty
	 * @return list<string>
	 * @throws ConfigurationException
	 */
	private function findFiles(array $paths = []): array
	{
		$factory = new RunnerFactory;
		return $factory->createFileFinder($factory->resolve($this->config, $this->root, $this->commandLine))
			->findFiles($paths ?: $this->config->paths, configured: $this->config->paths);
	}


	/**
	 * The files the estimate checks, within the paths given or all the configured ones, those a generator wrote left out,
	 * which it counts apart.
	 * @param  list<string>  $paths  relative to the root
	 * @throws ConfigurationException
	 */
	public function findUpgradeFiles(array $paths = []): UpgradeFiles
	{
		return UpgradeFiles::fromScope($this->root, $this->findFiles($paths));
	}


	/**
	 * The style a rewrite keeps: the decisions the configuration file makes, the types left out, less each one the code
	 * violates anywhere, whatever its severity; none without a configuration file.
	 * @return list<string>
	 * @throws ConfigurationException
	 */
	public function measureSatisfiedRequirements(): array
	{
		if ($this->configFile === null) {
			return [];
		}

		try {
			$factory = new RunnerFactory(phpstanInstalled: false);
			$resolution = $factory->resolve($this->config, $this->root, $this->commandLine);
		} catch (ConfigurationException) { // a decision of the project needs the types, so the check takes them
			$factory = new RunnerFactory;
			$resolution = $factory->resolve($this->config, $this->root, $this->commandLine);
		}

		$runner = $factory->createRunner($resolution, configFile: $this->configFile);
		$collected = new CollectingReporter;
		$runner->run($runner->files->findFiles($this->config->paths, configured: $this->config->paths), false, $collected);
		$violated = [];
		foreach ($collected->violations as $violations) {
			foreach ($violations as $violation) {
				$violated[$violation->decision] = true;
			}
		}

		$satisfied = [];
		foreach ($resolution->resolvedConfig->decisions as $path => $decision) {
			if (
				$decision->inactive === null
				&& $decision->decision->isRequirement()
				&& !$decision->value->isKept()
				&& !isset($violated[$path])
			) {
				$satisfied[] = $path;
			}
		}

		return $satisfied;
	}


	public function getCacheDir(): string
	{
		return RunnerFactory::resolveCacheDir($this->config, $this->root);
	}
}
