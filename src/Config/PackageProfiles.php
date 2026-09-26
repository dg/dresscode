<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{ConfigurationException, Extension, Group, Profile};
use Nette\Neon\{Exception as NeonException, Neon};
use function is_array, is_string;


/**
 * What the installed packages bring to a project without being named in its configuration: the upgrading files under
 * `extra.dresscode.upgrading`, cut to the sections the version of their package reaches (ProjectPackages::findVersion()),
 * a later one having the last word on an entry, and the extension under `extra.dresscode.extension`. The root package
 * takes part too, and a file about the root itself applies whole. Such a file never turns a rule on itself; the
 * `group` it has to name does, where the project turns that group on.
 * @internal
 */
final class PackageProfiles
{
	private const SectionPattern = '~^since (\d+(?:\.\d+)*)$~D';
	private const PackagePattern = '~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D';


	private function __construct(
		/** @var list<PackageProfile>  the root package first */
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

			foreach ((array) ($extra['upgrading'] ?? []) as $file) {
				if (!is_string($file)) {
					throw new ConfigurationException("Package $name: extra.dresscode.upgrading in its composer.json holds something that is not a file name.");
				} elseif ($path === null) {
					$warnings[] = "Package $name ships the upgrading file $file, but Composer says nothing about where it is installed; skipped.";
					continue;
				}

				$source = "$file of $name";
				$profile = self::readFile("$path/$file", $source, $project);
				if ($profile !== null) {
					$profiles[] = $profile;
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
	 * A value is taken as NEON gives it, an entity too, for the schema of the rule to read.
	 * @throws ConfigurationException
	 */
	public static function readFile(string $file, string $source, ProjectPackages $project): ?PackageProfile
	{
		if (!is_file($file)) {
			throw new ConfigurationException("Upgrading file $source does not exist.");
		}

		try {
			$data = Neon::decodeFile($file);
		} catch (NeonException $e) {
			throw new ConfigurationException("Upgrading file $source is not valid NEON: {$e->getMessage()}", previous: $e);
		}

		$package = is_array($data) ? $data['package'] ?? null : null;
		if (!is_string($package) || !preg_match(self::PackagePattern, $package)) {
			throw new ConfigurationException("Upgrading file $source: The key 'package' must name the package the sections are versions of, as vendor/name.");
		}

		$group = is_string($data['group'] ?? null) ? Group::tryFrom($data['group']) : null;
		if ($group === null) {
			$names = implode("', '", array_map(fn(Group $group) => $group->value, Group::cases()));
			throw new ConfigurationException("Upgrading file $source: The key 'group' must name the group the data are of, one of '$names'.");
		}

		/** @var array<string, array<string, array<string, mixed>>> $sections  version => rule => its options */
		$sections = [];
		foreach ($data as $key => $section) {
			if ($key === 'package' || $key === 'group') {
				continue;
			} elseif (!is_string($key) || !preg_match(self::SectionPattern, $key, $m)) {
				throw new ConfigurationException("Upgrading file $source: Unexpected key '$key'; the file holds 'package', 'group' and sections 'since <version>'.");
			} elseif (!is_array($section) || (array_is_list($section) && $section !== [])) {
				throw new ConfigurationException("Upgrading file $source: The section '$key' must be a map of rules to their options.");
			}

			foreach ($section as $rule => $options) {
				if (!is_string($rule) || !is_array($options)) {
					throw new ConfigurationException("Upgrading file $source: The rule '$rule' in '$key' must be a map of options; a package turns no rule on.");
				}

				$sections[$m[1]][$rule] = $options;
			}
		}

		if (!$project->has($package)) {
			return null;
		}

		$version = $project->findVersion($package);
		uksort($sections, fn(string $a, string $b) => version_compare($a, $b));
		$rules = $unreached = [];
		foreach ($sections as $since => $section) {
			if ($version !== null && version_compare($version, (string) $since, '<')) {
				$unreached[] = (string) $since;
				continue;
			}

			foreach ($section as $rule => $options) {
				$rules[$rule] = array_replace($rules[$rule] ?? [], $options);
			}
		}

		return new PackageProfile($source, $package, new Profile(rules: $rules), $group, $unreached);
	}
}
