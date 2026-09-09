<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{ConfigurationException, Extension, Profile};
use Nette\Neon\{Exception as NeonException, Neon};
use function is_array, is_string;


/**
 * What the installed packages bring to a project without being named in its configuration: the deprecations a
 * package ships under `extra.dresscode.deprecations` of its composer.json, what its versions retired and what to
 * write instead, as the options of the rules of DressCode in sections `since <version>`, of which the ones the
 * version the project stands on reaches apply (ProjectPackages::findVersion()); and the extension a package names
 * under `extra.dresscode.extension`. The root package takes part too, and its own file applies whole. Such a file
 * never turns a rule on: it speaks only for a rule the project runs.
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
	public static function discover(ProjectPackages $project): self
	{
		// the root package first, then the installed ones as Composer lists them
		$packages = $project->rootPath === null
			? []
			: [[$project->rootName ?? 'the root package', $project->rootPath, $project->rootExtra['dresscode'] ?? null]];
		foreach ($project->installed as $name => $package) {
			$packages[] = [$name, $package['path'], $package['extra']['dresscode'] ?? null];
		}

		$profiles = $extensions = $warnings = [];
		foreach ($packages as [$name, $path, $extra]) {
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
				$profile = self::readDeprecations("$path/$file", $source, $project);
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
	 * The file as a profile, cut to the sections the version the project stands on of the package it names reaches:
	 * every one where any version does, none for a package the project does not have, in which case it is null.
	 * @throws ConfigurationException
	 */
	private static function readDeprecations(string $file, string $source, ProjectPackages $project): ?Profile
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

		if (!$project->has($package)) {
			return null;
		}

		$version = $project->findVersion($package);
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
}
