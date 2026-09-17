<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{ConfigurationException, Plugin, Profile};
use Nette\Neon\{Exception as NeonException, Neon};
use function array_key_exists, is_array, is_string;


/**
 * What the installed packages bring to a project without being named in its configuration: the upgrading files under
 * `extra.dresscode.upgrading`, cut to the sections the version of their package reaches
 * (`ProjectPackages::findVersion()`), a later one having the last word on an entry, and the plugin under
 * `extra.dresscode.plugin`, which the project lets in by naming the package or the plugin in `use`, as Composer lets in
 * its own plugins. The root package takes part too, and a file about the root itself applies whole. Such a file never
 * turns a rule on itself; its maps are laid under those of the project where the project says
 * `upgrading.libraries.packages: adopted`.
 * @internal
 */
final readonly class PackageDiscovery
{
	/** the keys of `extra.dresscode` a package may use */
	public const Keys = ['upgrading', 'plugin'];

	private const SectionPattern = '~^since (\d+(?:\.\d+)*)$~D';
	private const PackagePattern = '~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D';


	private function __construct(
		/** @var list<UpgradingData>  the root package first */
		public array $upgradingData,
		/** @var array<string, class-string<Plugin>>  package => the plugin it brings, which loads where `use` names one of them */
		public array $plugins,
		/** @var list<string> */
		public array $warnings,
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

		$upgradingData = $plugins = $warnings = [];
		foreach ($packages as [$name, $path, $extra]) {
			if (!is_array($extra)) {
				continue;
			}

			// a package may be newer than this DressCode and say what it does not know yet
			foreach (array_diff(array_keys($extra), self::Keys) as $key) {
				$warnings[] = "Package `$name`: `extra.dresscode` in its `composer.json` holds the key `$key`, which this DressCode does not know; skipped.";
			}

			foreach ((array) ($extra['upgrading'] ?? []) as $file) {
				if (!is_string($file)) {
					throw new ConfigurationException("Package `$name`: `extra.dresscode.upgrading` in its `composer.json` holds something that is not a file name.");
				} elseif ($path === null) {
					$warnings[] = "Package `$name` ships the upgrading file `$file`, but Composer says nothing about where it is installed; skipped.";
					continue;
				}

				$data = self::readFile("$path/$file", new Layer(LayerKind::Package, $file, $name), $project);
				if ($data !== null) {
					$upgradingData[] = $data;
				}
			}

			$plugin = $extra['plugin'] ?? null;
			if (is_string($plugin)) {
				if (is_subclass_of($plugin, Plugin::class)) {
					$plugins[$name] = $plugin;
				} elseif (!class_exists($plugin)) {
					$warnings[] = "Package `$name` names the plugin `$plugin`, which the autoloader does not find; skipped.";
				} else {
					$warnings[] = "Package `$name` names the plugin `$plugin`, which is not a plugin; skipped.";
				}
			}
		}

		return new self($upgradingData, $plugins, $warnings);
	}


	/**
	 * The plugin the package of the class names in `extra.dresscode.plugin`, the package being the nearest composer.json
	 * above the file of the class, as a test of a rule of a plugin finds the decisions its manifest declares; null where
	 * it names none.
	 * @param  class-string  $class
	 */
	public static function findPluginOf(string $class): ?Plugin
	{
		static $plugins = [];
		$file = new \ReflectionClass($class)->getFileName();
		$composerFile = $file === false ? null : Composer::findFile(dirname($file));
		if ($composerFile === null) {
			return null;
		} elseif (!array_key_exists($composerFile, $plugins)) {
			$plugin = Composer::read($composerFile)['extra']['dresscode']['plugin'] ?? null;
			$plugins[$composerFile] = is_string($plugin) && is_subclass_of($plugin, Plugin::class) ? new $plugin : null;
		}

		return $plugins[$composerFile];
	}


	/**
	 * The file as upgrading data, cut to the sections the version the project stands on of the package it names reaches:
	 * every one where any version does, none for a package the project does not have, in which case it is null.
	 * A value is taken as NEON gives it, an entity too, for the grammar of the map to read.
	 * @throws ConfigurationException
	 */
	public static function readFile(string $file, Layer $layer, ProjectPackages $project): ?UpgradingData
	{
		$label = $layer->format();
		if (!is_file($file)) {
			throw new ConfigurationException("Upgrading file $label does not exist.");
		}

		try {
			$data = Neon::decodeFile($file);
		} catch (NeonException $e) {
			throw new ConfigurationException("Upgrading file $label is not valid NEON: {$e->getMessage()}", previous: $e);
		}

		$package = is_array($data) ? $data['package'] ?? null : null;
		if (!is_string($package) || !preg_match(self::PackagePattern, $package)) {
			throw new ConfigurationException("Upgrading file $label: The key `package` must name the package the sections are versions of, as `vendor/name`.");
		}

		/** @var array<string, array<string, array<string, mixed>>> $sections  version => map => its entries */
		$sections = [];
		foreach ($data as $key => $section) {
			if ($key === 'package' || $key === 'namespaces') {
				continue;
			} elseif (!is_string($key) || !preg_match(self::SectionPattern, $key, $m)) {
				throw new ConfigurationException("Upgrading file $label: Unexpected key `$key`; the file holds `package`, `namespaces` and sections `since <version>`.");
			} elseif (!is_array($section) || (array_is_list($section) && $section !== [])) {
				throw new ConfigurationException("Upgrading file $label: The section `$key` must be a map of maps to their entries.");
			}

			foreach ($section as $map => $entries) {
				if (!is_string($map) || !is_array($entries)) {
					throw new ConfigurationException("Upgrading file $label: The map `$map` in `$key` must be a map of entries; a package turns no rule on.");
				}

				$sections[$m[1]][$map] = $entries;
			}
		}

		if (!$project->has($package)) {
			return null;
		}

		$version = $project->findVersion($package);
		uksort($sections, fn(string $a, string $b) => version_compare($a, $b));
		$maps = [];
		foreach ($sections as $since => $section) {
			if ($version !== null && version_compare($version, (string) $since, '<')) {
				continue;
			}

			foreach ($section as $map => $entries) {
				$maps[$map] = array_replace($maps[$map] ?? [], $entries);
			}
		}

		try {
			$namespaces = new Profile(namespaces: is_array($data['namespaces'] ?? null) ? $data['namespaces'] : [])->namespaces;
		} catch (\InvalidArgumentException $e) {
			throw new ConfigurationException("Upgrading file $label: {$e->getMessage()}", previous: $e);
		}

		return new UpgradingData($layer, $package, $namespaces, $maps);
	}
}
