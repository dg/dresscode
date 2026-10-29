<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use Composer\InstalledVersions;
use DressCode\Config\{PackageDiscovery, ProjectPackages};
use DressCode\Console\Application;


/**
 * What a plan is made with: the version of DressCode and those of the packages of upgrading data, without which a step
 * may fix more or less than it did when the plan was made.
 * @internal
 */
final readonly class Provenance
{
	public function __construct(
		public string $tool,
		/** @var array<string, string>  package of upgrading data => its version, `php` for the data DressCode ships */
		public array $data,
	) {
	}


	/** What a plan made now is made with, the upgrading data the project and DressCode have; those of PHP travel with DressCode. */
	public static function current(ProjectPackages $project): self
	{
		$versions = [PackageDiscovery::Php => Application::Version];
		foreach ($project->installed as $package => $installed) {
			if (isset($installed['extra']['dresscode']['upgrading-dir'])) {
				$versions[$package] = $installed['version'] ?? 'dev';
			}
		}

		foreach (array_keys(PackageDiscovery::findInstalledDataPackages()) as $package) {
			$versions[$package] ??= InstalledVersions::getPrettyVersion($package) ?? 'dev';
		}

		ksort($versions);
		return new self(Application::Version, $versions);
	}
}
