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
final readonly class Resolution
{
	public function __construct(
		public Config $config,
		public string $root,
		/** what the configuration comes to for a file no override matches */
		public ResolvedConfig $resolvedConfig,
		/**
		 * The version the code targets and where it came from; the caller must not resolve it again, or the
		 * header could name something else than the rules were chosen for.
		 * @var array{string, PhpVersionSource}
		 */
		public array $phpVersion,
		/** @var list<string>  what the user should be told about the configuration, each thing once */
		public array $warnings,
		/** @var list<array{PackageProfile, ?string}>  the upgrading files of the installed packages, each with the version of its package the code must work with, null where any does */
		public array $packages,
		/** @var list<PluginManifest>  the plugins of the packages and of the configuration, a plugin after those it builds on */
		public array $plugins,
		public ProjectPackages $project,
		/** laid over the configuration and its overrides, as --preset and --rule are */
		public ?Profile $commandLine,
		/** @var ?list<string>  names or classes of the rules and presets the run is narrowed to */
		public ?array $only,
		private ConfigResolver $resolver,
		/** the versions the code is written for unless an override says another */
		private string $target,
	) {
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
