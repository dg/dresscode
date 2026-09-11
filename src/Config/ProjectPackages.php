<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use Composer\Semver\VersionParser;
use DressCode\Engine\Helpers;
use function is_array, is_string;


/**
 * The packages a project stands on, read from its composer.json and from the vendor/composer/installed.json beside it,
 * and the versions of each the code must work with. For a package the project requires itself those are the versions its
 * constraint allows, the installed one where the constraint has no lower bound, because code written for a newer one
 * breaks wherever the constraint lets an older one in; for a package that only comes with another it is the installed
 * one; and any version does for the project itself and for a development branch without an alias, whose version says
 * nothing.
 * @internal
 */
final readonly class ProjectPackages
{
	public function __construct(
		/** the name of the root package, null where its composer.json gives none */
		public ?string $rootName = null,
		/** @var array<string, string>  package the project requires itself => its constraint */
		private array $required = [],
		/** @var array<string, array{version: ?string, reference: ?string}>  installed package => the version it stands for (null for any) and the source it came from */
		public array $installed = [],
	) {
	}


	public static function read(string $root): self
	{
		$composerFile = Composer::findFile($root);
		if ($composerFile === null) {
			return new self;
		}

		$base = Helpers::canonicalizePath(dirname($composerFile));
		$composer = Composer::read($composerFile) ?? [];
		$vendorDir = $composer['config']['vendor-dir'] ?? 'vendor';
		$vendor = Helpers::canonicalizePath(RunnerFactory::toAbsolutePath(is_string($vendorDir) ? $vendorDir : 'vendor', $base));

		$required = [];
		foreach (['require', 'require-dev'] as $section) {
			foreach (is_array($composer[$section] ?? null) ? $composer[$section] : [] as $package => $constraint) {
				if (is_string($constraint)) {
					$required[(string) $package] = $constraint;
				}
			}
		}

		$installed = [];
		$data = Composer::read("$vendor/composer/installed.json") ?? [];
		foreach (is_array($data['packages'] ?? null) ? $data['packages'] : $data as $package) {
			if (!is_array($package) || !is_string($package['name'] ?? null)) {
				continue;
			}

			$reference = $package['source']['reference'] ?? $package['dist']['reference'] ?? null;
			$installed[$package['name']] = [
				'version' => self::findInstalledVersion($package),
				'reference' => is_string($reference) ? $reference : null,
			];
		}

		return new self(
			is_string($composer['name'] ?? null) ? $composer['name'] : null,
			$required,
			$installed,
		);
	}


	/** Whether the project has the package: it is the project itself, or it is installed. */
	public function has(string $package): bool
	{
		return $package === $this->rootName || isset($this->installed[$package]);
	}


	/**
	 * The versions of the package the code must work with, as a Composer constraint: the constraint the project
	 * requires it with, or the installed version, `^3.1` or `3.2.1`; null where any version does, and for a package
	 * the project does not have, which `has()` tells apart.
	 */
	public function findConstraint(string $package): ?string
	{
		$required = isset($this->required[$package]) && Versions::findLowestVersion($this->required[$package]) !== null
			? $this->required[$package]
			: null;
		if ($package === $this->rootName || !isset($this->installed[$package])) {
			return null;
		}

		return $required ?? $this->installed[$package]['version'];
	}


	/**
	 * The first of the required packages the project does not meet; null where it meets every one.
	 * @param  array<string, string>  $required  package => Composer constraint
	 */
	public function findUnmetRequirement(array $required): ?UnmetRequirement
	{
		foreach ($required as $package => $constraint) {
			if (!$this->has($package)) {
				return new UnmetRequirement($package, $constraint, null);
			}

			$current = $this->findConstraint($package);
			if ($current !== null && !Versions::isSubset($current, $constraint)) {
				return new UnmetRequirement($package, $constraint, $current);
			}
		}

		return null;
	}


	/**
	 * What the packages contribute to the identity of a result: every installed one with the version it stands for
	 * and the reference of the source it came from, so that a package upgraded under the same constraint invalidates
	 * what was cached with the files it had before. A constraint the project widens or narrows is not here, because
	 * that is answered by the rules the resolution comes to.
	 * @return array<string, array{?string, ?string}>
	 */
	public function getIdentity(): array
	{
		$identity = array_map(fn(array $package) => [$package['version'], $package['reference']], $this->installed);
		ksort($identity);
		return $identity;
	}


	/**
	 * The version an installed package stands for: its normalized version, the alias of its branch for a development
	 * branch, and null for a development branch without one.
	 * @param  array<mixed>  $package  its entry in installed.json
	 */
	private static function findInstalledVersion(array $package): ?string
	{
		$version = $package['version_normalized'] ?? null;
		if (!is_string($version)) {
			return null;
		} elseif (!str_starts_with($version, 'dev-') && !str_starts_with($version, '9999999')) {
			return Versions::shortenVersion($version);
		}

		// Composer reads the alias 3.3-dev of a branch as the newest of the 3.3 line
		$alias = $package['extra']['branch-alias'][$package['version'] ?? ''] ?? null;
		$branch = is_string($alias) && str_ends_with($alias, '-dev')
			? (new VersionParser)->normalizeBranch(substr($alias, 0, -4))
			: null;
		return $branch !== null && str_ends_with($branch, '-dev') ? Versions::shortenVersion($branch) : null;
	}
}
