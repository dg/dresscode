<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use Composer\Semver\VersionParser;
use DressCode\Helpers;
use Nette\Utils\FileSystem;
use function count, dirname, is_array, is_string;


/**
 * The packages a project stands on, read from its composer.json and from the vendor/composer/installed.json beside it,
 * and the version of each the code must work with. For a package the project requires itself that is the lowest version
 * its constraint allows, the installed one where the constraint has no lower bound, because code written for a newer one breaks wherever the constraint lets an older one in; for
 * a package that only comes with another it is the installed one; and any version does for the project itself and for
 * a development branch without an alias, whose version says nothing. A package an installed one replaces with its own
 * version, a part of a monorepo installed as the whole, is had in the version of the one replacing it. The `packages` of
 * the configuration say the version of an installed package outright (withTargets()).
 * @internal
 */
final class ProjectPackages
{
	public function __construct(
		/** the name of the root package, null where its composer.json gives none */
		public readonly ?string $rootName = null,
		/** the directory of the root package, null where the project has no composer.json */
		public readonly ?string $rootPath = null,
		/** @var array<mixed>  what the composer.json of the root package says under extra */
		public readonly array $rootExtra = [],
		/** @var array<string, string>  package the project requires itself → its constraint */
		private readonly array $required = [],
		/** @var array<string, array{version: ?string, reference: ?string, path: ?string, extra: array<mixed>}>  installed package → the version it stands for (null for any), the source it came from, where it lies and its extra */
		public readonly array $installed = [],
		/** @var array<string, string>  package → the version the configuration says the code is written for */
		private readonly array $targets = [],
		/** @var array<string, string>  package → the installed one that replaces it with its own version */
		private readonly array $replaced = [],
	) {
	}


	public static function read(string $root): self
	{
		$composerFile = RunnerFactory::findComposerFile($root);
		if ($composerFile === null) {
			return new self;
		}

		$base = Helpers::canonicalizePath(dirname($composerFile));
		$composer = self::readJson($composerFile) ?? [];
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

		$installed = $replaced = [];
		$data = self::readJson("$vendor/composer/installed.json") ?? [];
		foreach (is_array($data['packages'] ?? null) ? $data['packages'] : $data as $package) {
			if (!is_array($package) || !is_string($package['name'] ?? null)) {
				continue;
			}

			$reference = $package['source']['reference'] ?? $package['dist']['reference'] ?? null;
			$installed[$package['name']] = [
				'version' => self::findInstalledVersion($package),
				'reference' => is_string($reference) ? $reference : null,
				'path' => is_string($package['install-path'] ?? null)
					? Helpers::canonicalizePath(FileSystem::normalizePath("$vendor/composer/{$package['install-path']}"))
					: null,
				'extra' => is_array($package['extra'] ?? null) ? $package['extra'] : [],
			];
			foreach (is_array($package['replace'] ?? null) ? $package['replace'] : [] as $name => $constraint) {
				if ($constraint === 'self.version') {
					$replaced[(string) $name] = $package['name'];
				}
			}
		}

		return new self(
			is_string($composer['name'] ?? null) ? $composer['name'] : null,
			$base,
			is_array($composer['extra'] ?? null) ? $composer['extra'] : [],
			$required,
			$installed,
			replaced: array_diff_key($replaced, $installed),
		);
	}


	/**
	 * The same project with the versions of packages its code is written for said outright, which findVersion()
	 * answers with before it asks the constraint: code is fixed for a version before the project moves to it.
	 * @param  array<string, string>  $versions  package → version
	 */
	public function withTargets(array $versions): self
	{
		return new self($this->rootName, $this->rootPath, $this->rootExtra, $this->required, $this->installed, $versions, $this->replaced);
	}


	/** Whether the project has the package: it is the project itself, it is installed, or an installed one replaces it. */
	public function has(string $package): bool
	{
		return $package === $this->rootName || isset($this->installed[$package]) || isset($this->replaced[$package]);
	}


	/**
	 * The version of the package the code must work with, `3.1` or `3.2.1`; null where any version does, and for
	 * a package the project does not have, which has() tells apart.
	 */
	public function findVersion(string $package): ?string
	{
		if (isset($this->replaced[$package])) {
			$lowest = isset($this->required[$package]) ? self::findLowestVersion($this->required[$package]) : null;
			return $this->targets[$package] ?? $lowest ?? $this->findVersion($this->replaced[$package]);
		} elseif ($package === $this->rootName || !isset($this->installed[$package])) {
			return null;
		}

		$lowest = isset($this->required[$package]) ? self::findLowestVersion($this->required[$package]) : null;
		return $this->targets[$package] ?? $lowest ?? $this->installed[$package]['version'];
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
	 * The lowest version a Composer constraint allows, without its stability and the zeros it only pads with, `3.1`
	 * for `^3.1 || ^4.0`; null where the constraint has no lower bound (`<8.4`, `*`, `dev-master`) or is no constraint.
	 */
	public static function findLowestVersion(string $constraint): ?string
	{
		try {
			$bound = (new VersionParser)->parseConstraints($constraint)->getLowerBound();
		} catch (\UnexpectedValueException) {
			return null;
		}

		return $bound->isZero() ? null : self::trimVersion($bound->getVersion());
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
			return self::trimVersion($version);
		}

		$alias = $package['extra']['branch-alias'][$package['version'] ?? ''] ?? null;
		if (!is_string($alias)) {
			return null;
		}

		try {
			// Composer reads the alias 3.3-dev of a branch as 3.3.x-dev, the newest of the 3.3 line
			return self::trimVersion((new VersionParser)->normalize((string) preg_replace('~^(\d+\.\d+)-dev$~D', '$1.x-dev', $alias)));
		} catch (\UnexpectedValueException) {
			return null;
		}
	}


	/** A normalized version without its stability and the zeros it only pads with: `3.1.0.0-dev` is `3.1`. */
	private static function trimVersion(string $version): string
	{
		$parts = explode('.', (string) preg_replace('~-.*$~D', '', $version));
		while (count($parts) > 2 && $parts[count($parts) - 1] === '0') {
			array_pop($parts);
		}

		return implode('.', $parts);
	}


	/** @return ?array<mixed> */
	private static function readJson(string $file): ?array
	{
		$json = @file_get_contents($file); // @ - the file is optional
		$data = $json === false ? null : json_decode($json, associative: true);
		return is_array($data) ? $data : null;
	}
}
