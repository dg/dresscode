<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\ConfigurationException;
use DressCode\Extension;
use DressCode\Helpers;
use DressCode\Profile;
use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use function dirname, is_array, is_string;


/**
 * What the installed packages bring to a project without being named in its configuration: the deprecations a
 * package ships under `extra.dresscode.deprecations` of its composer.json, what its versions retired and what to
 * write instead, as the options of the rules of DressCode in sections `since <version>`, of which the ones the
 * installed version of the package reaches apply; and the extension a package names under
 * `extra.dresscode.extension`. Both are read from vendor/composer/installed.json of the nearest composer.json, the
 * root package included, whose own file applies whole because its version says nothing. Such a file never turns
 * a rule on: it speaks only for a rule the project runs.
 * @internal
 */
final class PackageProfiles
{
	private const SectionPattern = '~^since (\d+(?:\.\d+)*)$~D';
	private const PackagePattern = '~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D';


	private function __construct(
		/** @var list<array{string, Profile}>  where it comes from and what it says, the root package first */
		public readonly array $profiles,
		/** @var list<class-string<Extension>> */
		public readonly array $extensions,
		/** @var list<string> */
		public readonly array $warnings,
	) {
	}


	/** @throws ConfigurationException  for a file a package ships that cannot be read */
	public static function discover(string $root): self
	{
		$composerFile = RunnerFactory::findComposerFile($root);
		if ($composerFile === null) {
			return new self([], [], []);
		}

		$base = Helpers::canonicalizePath(dirname($composerFile));
		$composer = self::readJson($composerFile) ?? [];
		$vendorDir = $composer['config']['vendor-dir'] ?? 'vendor';
		$vendor = Helpers::canonicalizePath(RunnerFactory::toAbsolutePath(is_string($vendorDir) ? $vendorDir : 'vendor', $base));

		// the root package first, whose version is unknown, then the installed ones as Composer lists them
		$rootName = is_string($composer['name'] ?? null) ? $composer['name'] : 'the root package';
		$packages = [[$rootName, null, $base, $composer['extra']['dresscode'] ?? null]];
		$versions = [];
		$installed = self::readJson("$vendor/composer/installed.json") ?? [];
		foreach (is_array($installed['packages'] ?? null) ? $installed['packages'] : $installed as $package) {
			if (!is_array($package) || !is_string($package['name'] ?? null)) {
				continue;
			}

			$version = $package['version_normalized'] ?? $package['version'] ?? null;
			$versions[$package['name']] = is_string($version) ? $version : null;
			$path = is_string($package['install-path'] ?? null)
				? Helpers::canonicalizePath(FileSystem::normalizePath("$vendor/composer/{$package['install-path']}"))
				: null;
			$packages[] = [$package['name'], $versions[$package['name']], $path, $package['extra']['dresscode'] ?? null];
		}

		$profiles = $extensions = $warnings = [];
		foreach ($packages as [$name, $version, $path, $extra]) {
			if (!is_array($extra)) {
				continue;
			}

			foreach ((array) ($extra['deprecations'] ?? []) as $file) {
				if (!is_string($file)) {
					throw new ConfigurationException("Package $name names something that is not a file name in extra.dresscode.deprecations of its composer.json.");
				} elseif ($path === null) {
					$warnings[] = "Package $name ships the deprecations $file, but Composer says nothing about where it is installed; skipped.";
					continue;
				}

				$source = "$file of $name";
				$profile = self::readDeprecations("$path/$file", $source, $name, $version, $versions);
				if ($profile !== null) {
					$profiles[] = [$source, $profile];
				}
			}

			$extension = $extra['extension'] ?? null;
			if (is_string($extension)) {
				if (is_subclass_of($extension, Extension::class)) {
					$extensions[] = $extension;
				} else {
					$warnings[] = "Package $name names the extension $extension, which does not exist or is not an extension; skipped.";
				}
			}
		}

		return new self($profiles, $extensions, $warnings);
	}


	/**
	 * The file as a profile, cut to the sections the installed version of the package it names reaches: every one for
	 * the root package, none for a package that is not installed, in which case it is null.
	 * @param  ?string  $carrierVersion  the normalized version of the package shipping the file, null for the root package
	 * @param  array<string, ?string>  $versions  installed package → its normalized version
	 * @throws ConfigurationException
	 */
	private static function readDeprecations(
		string $file,
		string $source,
		string $carrier,
		?string $carrierVersion,
		array $versions,
	): ?Profile
	{
		if (!is_file($file)) {
			throw new ConfigurationException("Deprecations $source do not exist.");
		}

		try {
			$data = Neon::decodeFile($file);
		} catch (NeonException $e) {
			throw new ConfigurationException("Deprecations $source: {$e->getMessage()}", previous: $e);
		}

		$package = is_array($data) ? $data['package'] ?? null : null;
		if (!is_string($package) || !preg_match(self::PackagePattern, $package)) {
			throw new ConfigurationException("Deprecations $source: 'package' must name the package the sections are versions of, as vendor/name.");
		}

		/** @var array<string, array<string, array<string, mixed>>> $sections  version → rule → its options */
		$sections = [];
		foreach ($data as $key => $section) {
			if ($key === 'package') {
				continue;
			} elseif (!is_string($key) || !preg_match(self::SectionPattern, $key, $m)) {
				throw new ConfigurationException("Deprecations $source: unexpected key '$key'; the file holds 'package' and sections 'since <version>'.");
			} elseif (!is_array($section) || (array_is_list($section) && $section !== [])) {
				throw new ConfigurationException("Deprecations $source: '$key' must be a map of rules to their options.");
			}

			foreach ($section as $rule => $options) {
				if (!is_string($rule) || !is_array($options)) {
					throw new ConfigurationException("Deprecations $source: '$rule' in '$key' must be a map of options; a package turns no rule on.");
				}

				$sections[$m[1]][$rule] = $options;
			}
		}

		$version = strcasecmp($package, $carrier) === 0 ? $carrierVersion : ($versions[$package] ?? false);
		if ($version === false) {
			return null;
		}

		uksort($sections, fn(string $a, string $b) => version_compare($a, $b));
		$rules = [];
		foreach ($sections as $since => $section) {
			if ($version === null || version_compare($version, $since, '>=')) {
				foreach ($section as $rule => $options) {
					$rules[$rule] = array_replace($rules[$rule] ?? [], $options);
				}
			}
		}

		return new Profile(rules: $rules);
	}


	/** @return ?array<mixed> */
	private static function readJson(string $file): ?array
	{
		$json = @file_get_contents($file); // @ - the file is optional
		$data = $json === false ? null : json_decode($json, associative: true);
		return is_array($data) ? $data : null;
	}
}
