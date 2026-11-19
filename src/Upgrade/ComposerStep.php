<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\{PackageDiscovery, ProjectPackages, Versions};
use function array_key_exists;


/**
 * The step raising the constraints of `composer.json` and updating the packages they move, or widening them alone, or
 * raising the platform of PHP alone.
 * @internal
 */
final readonly class ComposerStep implements DependencyStep
{
	public function __construct(
		public string $title,
		/** @var array<string, string>  package of `require` => the constraint written */
		public array $require,
		/** the packages updated too, not only `composer.json` */
		public bool $update = true,
		/** the version `config.platform.php` is raised to beside the constraint of PHP, where the project sets it lower */
		public ?string $platform = null,
		public bool $skip = false,
	) {
	}


	public function formatCommands(): array
	{
		$commands = $this->platform === null ? [] : ["composer config platform.php $this->platform"];
		if ($this->require !== []) {
			$arguments = Composer::buildRequire($this->require, $this->update);
			$commands[] = 'composer '
				. implode(' ', array_map(fn(string $argument) => str_contains($argument, ':') ? "\"$argument\"" : $argument, $arguments));
		} elseif ($this->update) { // the platform alone, which the lock is to say it was resolved for
			$commands[] = 'composer update --lock';
		}

		return $commands;
	}


	/**
	 * Whether the project has the step done: `composer.json` requires every package of the step with a lower bound the
	 * step reaches, allowing the newest version the step names, `^8.4` and `~8.4.0` raising `^8.1` to `^8.4` and
	 * `^3.2 || ^4.0` widening `^3.2`, and for a step that updates, the versions installed and locked are ones the step
	 * allows, for PHP the platform the project sets, which reaches the one the step raises it to.
	 * @phpstan-impure
	 */
	public function isDoneIn(ProjectPackages $project): bool
	{
		$locked = $this->update ? $project->readLockedVersions() : null;
		return ($this->platform === null || version_compare($project->platformPhp ?? '0', $this->platform, '>='))
			&& array_all($this->require, function (string $constraint, string $package) use ($project, $locked): bool {
				$required = $project->required[$package] ?? null;
				$lowests = array_map(
					fn(string $part) => Versions::findLowestVersion($part) ?? '0',
					Versions::splitAlternatives($constraint),
				);
				usort($lowests, fn(string $a, string $b) => version_compare($a, $b));
				// false for a package missing, null for nothing to compare, as the version of a branch without an alias
				$installed = match (true) {
					$package === PackageDiscovery::Php => $project->platformPhp,
					!isset($project->installed[$package]) => false,
					default => $project->installed[$package]['version'],
				};
				$lock = match (true) {
					$locked === null || $package === PackageDiscovery::Php => null,
					!array_key_exists($package, $locked) => false,
					default => $locked[$package],
				};
				return $required !== null
					&& version_compare(Versions::findLowestVersion($required) ?? '0', $lowests[0] ?? '0', '>=')
					&& Versions::isSubset((string) end($lowests), $required)
					&& (!$this->update
						|| (($installed === null || ($installed !== false && Versions::isSubset($installed, $constraint)))
							&& ($lock === null || ($lock !== false && Versions::isSubset($lock, $constraint)))));
			});
	}
}
