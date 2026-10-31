<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\{Config, ConfigurationException};
use DressCode\Config\RunnerFactory;
use DressCode\Engine\Helpers;
use DressCode\Measuring\NamespaceDeclarations;
use DressCode\Reporters\NullReporter;


/**
 * The project an upgrade measures, plans or runs: its configuration, or the scope `init` would write where it has no
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
	 * The layer an upgrade lays over the configuration: the command line, the plugins of the packages of upgrading data,
	 * the targets, the types, and where they were measured the names resolved for certain with what the namespaces
	 * declare.
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
		$tree = $commandLine->decisions ?? [];
		foreach ($decisions as $path => $value) {
			$tree = Helpers::placeValue($tree, $path, $value);
		}

		return new Config(
			use: [...$commandLine->plugins ?? [], ...$commandLine->use ?? [], ...$plugins],
			targets: $targets,
			namespaces: $declarations === null ? [] : ['functions' => $declarations->functions, 'constants' => $declarations->constants],
			nameResolution: $declarations === null ? null : 'certain',
			fixRisky: $commandLine->fixRisky ?? [],
			severity: $commandLine->severity ?? [],
			typeAnalysis: $types ? 'phpstan' : null,
			decisions: $tree,
		);
	}


	/**
	 * What the namespaces of the scope declare, which resolves the names for certain where the configuration says nothing
	 * of them; null where it does, or where a file of the scope does not parse.
	 */
	public function collectDeclarations(): ?NamespaceDeclarations
	{
		if ($this->config->nameResolution !== null) {
			return null;
		}

		$declarations = NamespaceDeclarations::collect($this->root, $this->findFiles());
		return $declarations->unparsed === [] ? $declarations : null;
	}


	/**
	 * The files of the scope of the configuration, relative to the root.
	 * @return list<string>
	 * @throws ConfigurationException
	 */
	public function findFiles(): array
	{
		$factory = new RunnerFactory;
		$runner = $factory->createRunner($factory->resolve($this->config, $this->root, $this->commandLine), cache: false);
		return $runner->findFiles($this->config->paths, configured: $this->config->paths);
	}


	/**
	 * The style a rewrite keeps: the decisions the configuration file makes, the types left out, less each one the code
	 * violates anywhere, whatever its severity; none without a configuration file.
	 * @return list<string>
	 * @throws ConfigurationException
	 */
	public function findKeptDecisions(): array
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
		$result = $runner->run($runner->findFiles($this->config->paths, configured: $this->config->paths), false, new NullReporter);
		$violated = [];
		foreach ($result->files as $summary) {
			foreach ($summary->violations as $violation) {
				$violated[$violation->decision] = true;
			}
		}

		$kept = [];
		foreach ($resolution->resolvedConfig->decisions as $path => $decision) {
			if (
				$decision->inactive === null
				&& $decision->decision->isRequirement()
				&& !$decision->value->isKept()
				&& !isset($violated[$path])
			) {
				$kept[] = $path;
			}
		}

		return $kept;
	}


	public function getCacheDir(): string
	{
		return RunnerFactory::resolveCacheDir($this->config, $this->root);
	}
}
