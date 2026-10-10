<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException, PluginManifest, Profile};


/**
 * What a configuration comes to in a project: the resolved configuration of a file no override matches, the version
 * the code targets, what the packages contribute and what the user should be told, together with what a runner
 * built from it and the configuration of a file matching some overrides are resolved from.
 * @internal
 */
final readonly class ResolvedProject
{
	public function __construct(
		public Config $config,
		public string $root,
		/** what the configuration comes to for a file no override matches */
		public ResolvedConfig $resolvedConfig,
		/** where the version the code targets, `ResolvedConfig::$phpVersion`, came from */
		public PhpVersionSource $phpVersionSource,
		/** @var array<string, ?string>  what the user should be told about the configuration, each thing once => the page of the manual that says more, if any */
		public array $warnings,
		/** @var list<array{UpgradingData, ?string}>  the upgrading files of the installed packages, each with the version of its package the code must work with, null where any does */
		public array $upgradingData,
		/** @var list<PluginManifest>  the plugins of the packages and of the configuration, a plugin after those it builds on */
		public array $pluginManifests,
		public ProjectPackages $projectPackages,
		/** laid over the configuration and its overrides, as --use is */
		public ?Profile $commandLine,
		/** @var ?list<string>  decisions, sections, presets and classes of rules the run is narrowed to */
		public ?array $only,
		private ConfigResolver $resolver,
		/** the versions the code is written for unless an override says another */
		private string $target,
	) {
	}


	/** Every decision the rules of the run declare, those of the plugins and of the project among them. */
	public function getCatalogue(): Catalogue
	{
		return $this->resolver->getCatalogue();
	}


	/**
	 * What the configuration comes to for a file matching those overrides; the same resolution the run uses.
	 * @param  list<int>  $overrides
	 * @throws ConfigurationException
	 */
	public function resolveFor(array $overrides): ResolvedConfig
	{
		return $overrides === []
			? $this->resolvedConfig
			: $this->resolver->resolve($this->config, $this->target, $overrides, $this->commandLine, $this->only);
	}
}
