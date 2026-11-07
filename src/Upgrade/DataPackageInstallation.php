<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\{DataPackageCatalogue, PackageDiscovery, ProjectPackages};


/**
 * The packages of upgrading data the libraries of the project need and DressCode lacks, and where Composer installs
 * them: beside DressCode, or into the project with `require --dev` where its lock holds DressCode.
 * @internal
 */
final readonly class DataPackageInstallation
{
	private function __construct(
		/** @var non-empty-list<string> */
		public array $packages,
		/** the directory of the `composer.json` they go into; null for an installation Composer cannot add to, a phar */
		public ?string $directory,
		public bool $intoProject,
	) {
	}


	/** What the project needs installed; null where DressCode has every package of upgrading data it needs. */
	public static function find(string $root, string $cacheDir): ?self
	{
		$project = ProjectPackages::read($root);
		$missing = PackageDiscovery::findMissingDataPackages($project, DataPackageCatalogue::load($cacheDir));
		if ($missing === []) {
			return null;
		}

		$intoProject = $project->has('dresscode/dresscode');
		return new self($missing, $intoProject ? $root : PackageDiscovery::findInstallationRoot(), $intoProject);
	}


	/** The command of Composer that installs them, as a user would type it. */
	public function formatCommand(): string
	{
		$command = ['composer', 'require', ...$this->intoProject ? ['--dev'] : [], "--working-dir=$this->directory", ...$this->packages];
		return implode(' ', array_map(fn(string $arg) => str_contains($arg, ' ') ? "\"$arg\"" : $arg, $command));
	}


	/** @throws ComposerException */
	public function install(): void
	{
		new Composer((string) $this->directory)->add($this->packages, dev: $this->intoProject);
	}
}
