<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\{Config, ConfigurationException};
use DressCode\Config\RunnerFactory;
use DressCode\Reporters\NullReporter;


/**
 * The project an upgrade runs over: its configuration, or the directory as a whole where it has no file of its own,
 * and what the command line lays over it.
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
	 * The layer an upgrade lays over the configuration: the command line, the plugins of the packages of upgrading data
	 * and the types of the code.
	 * @param  list<string>  $plugins
	 */
	public function createLayer(array $plugins): Config
	{
		$commandLine = $this->commandLine;
		return new Config(
			use: [...$commandLine->plugins ?? [], ...$commandLine->use ?? [], ...$plugins],
			fixRisky: $commandLine->fixRisky ?? [],
			severity: $commandLine->severity ?? [],
			typeAnalysis: 'phpstan',
			decisions: $commandLine->decisions ?? [],
		);
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
}
