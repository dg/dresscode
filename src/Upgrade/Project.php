<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\{Config, ConfigurationException};
use DressCode\Config\{PackageDiscovery, ProjectPackages, RunnerFactory};
use DressCode\Measuring\{NamespaceDeclarations, Scope};
use DressCode\Reporters\CollectingReporter;
use function count;


/**
 * The project an upgrade runs over: its configuration, or the scope the command gives it where it has no file of its
 * own, and what the command line lays over it.
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
	 * own.
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


	/** The packages of upgrading data that reach the project. */
	public function discoverPackages(): PackageDiscovery
	{
		return PackageDiscovery::discover(new RunnerFactory()->readProjectPackages($this->config, $this->root));
	}


	/**
	 * The layer an upgrade lays over the configuration: the command line, the plugins of the packages of upgrading data,
	 * the types, and where they were measured in every file the names resolved for certain with what the namespaces
	 * declare.
	 * @param  list<string>  $plugins
	 */
	public function createLayer(array $plugins, ?NamespaceDeclarations $declarations): Config
	{
		$commandLine = $this->commandLine;
		$certain = $declarations !== null;
		return new Config(
			use: [...$commandLine->plugins ?? [], ...$commandLine->use ?? [], ...$plugins],
			namespaces: $certain ? ['functions' => $declarations->functions, 'constants' => $declarations->constants] : [],
			nameResolution: $certain ? 'certain' : null,
			fixRisky: $commandLine->fixRisky ?? [],
			severity: $commandLine->severity ?? [],
			typeAnalysis: 'phpstan',
			decisions: $commandLine->decisions ?? [],
		);
	}


	/**
	 * How the names are resolved where the configuration says nothing of them: for certain with what the namespaces of
	 * the scope declare, unless a file cannot be read or does not parse.
	 */
	public function resolveNames(): NameResolution
	{
		if ($this->config->nameResolution !== null) {
			return new NameResolution;
		}

		$declarations = NamespaceDeclarations::collect($this->root, $this->findFiles());
		return $declarations->unparsed === []
			? new NameResolution($declarations)
			: new NameResolution(warning: 'The names are not resolved for certain: ' . count($declarations->unparsed) . ' of the files cannot be read or do not parse.');
	}


	/**
	 * The files of the scope of the configuration, relative to the root.
	 * @return list<string>
	 * @throws ConfigurationException
	 */
	private function findFiles(): array
	{
		$factory = new RunnerFactory;
		return $factory->createFileFinder($factory->resolve($this->config, $this->root, $this->commandLine))
			->findFiles($this->config->paths, configured: $this->config->paths);
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
}
