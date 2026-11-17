<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{ConfigurationException, Profile};
use DressCode\Domains\Map;
use DressCode\Upgrading\MemberMapGrammar;
use Nette\Neon\{Exception as NeonException, Neon};
use function array_key_exists, is_array, is_string;


/**
 * An upgrading file as it is written: the package whose versions its sections are of, what the namespaces of the
 * package declare, and the maps every section brings with the requirements of the rules of the plugin of the package it
 * turns on, which `selectFor()` lays out for the versions of the package the code must work with.
 * @internal
 */
final readonly class UpgradingFile
{
	/** the maps of what the code must not have */
	private const ForbiddenMaps = ['forbiddenClasses', 'forbiddenFunctions', 'forbiddenConstants', 'forbiddenMembers'];

	private const SectionPattern = '~^since (.+)$~D';
	private const PackagePattern = '~^(?:php|[a-z0-9_.-]+/[a-z0-9_.-]+)$~D';
	private const VersionPattern = '~^\d+(?:\.\d+)*$~D';


	private function __construct(
		/** the package the sections are versions of, `php` for PHP itself */
		public string $package,
		/** @var array{functions: list<string>, constants: list<string>}  what the package declares in its namespaces, fully qualified */
		public array $namespaces,
		/** @var array<string, Since>  the versions as a section writes them => what they say, the oldest first */
		private array $since,
		/** @var array<string, array<string, array<string, mixed>>>  versions => map => its entries */
		private array $sections,
		/** @var array<string, array<string, string>>  versions => a requirement of the plugin of the package => its value */
		private array $requirements = [],
	) {
	}


	/**
	 * Reads the file, a value as NEON gives it, an entity too, for the grammar of the map to read.
	 * @throws ConfigurationException
	 */
	public static function fromFile(string $file, string $label): self
	{
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
			throw new ConfigurationException("Upgrading file $label: The key `package` must name the package the sections are versions of, as `vendor/name`, or `php`.");
		}

		$since = $sections = $requirements = [];
		foreach ($data as $key => $section) {
			if ($key === 'package' || $key === 'namespaces') {
				continue;
			} elseif (!is_string($key) || !preg_match(self::SectionPattern, $key, $m)) {
				throw new ConfigurationException("Upgrading file $label: Unexpected key `$key`; the file holds `package`, `namespaces` and sections `since <version>`.");
			} elseif (!is_array($section) || (array_is_list($section) && $section !== [])) {
				throw new ConfigurationException("Upgrading file $label: The section `$key` must be a map of maps to their entries.");
			}

			try {
				$since[$m[1]] = Since::parse($m[1]);
			} catch (\InvalidArgumentException $e) {
				throw new ConfigurationException("Upgrading file $label, `$key`: {$e->getMessage()}", previous: $e);
			}

			foreach ($section as $map => $entries) {
				if (is_string($map) && str_contains($map, '.') && is_string($entries)) {
					$requirements[$m[1]][$map] = $entries;
					continue;
				} elseif (!is_string($map) || !is_array($entries)) {
					throw new ConfigurationException("Upgrading file $label: The map `$map` in `$key` must be a map of entries; a package turns on only a rule of its own plugin, by the path of its decision and a word.");
				} elseif (in_array($map, self::ForbiddenMaps, true)) {
					try {
						$entries = Map::expandBareNames($entries, $map);
					} catch (ConfigurationException $e) {
						throw new ConfigurationException("Upgrading file $label, `$key`: {$e->getMessage()}", previous: $e);
					}
				}

				foreach ($entries as $entry => $value) {
					$from = is_array($value) ? $value['from'] ?? null : null;
					if ($from !== null && (!is_string($from) || !preg_match(self::VersionPattern, $from))) {
						throw new ConfigurationException("Upgrading file $label: `from` of the entry `$entry` of `$map` in `$key` must be a version, as `'8.0'`.");
					}
				}

				$sections[$m[1]][$map] = $entries;
			}
		}

		uasort($since, fn(Since $a, Since $b) => version_compare($a->getOldest(), $b->getOldest()));
		try {
			$namespaces = new Profile(namespaces: is_array($data['namespaces'] ?? null) ? $data['namespaces'] : [])->namespaces;
		} catch (\InvalidArgumentException $e) {
			throw new ConfigurationException("Upgrading file $label: {$e->getMessage()}", previous: $e);
		}

		return new self($package, $namespaces, $since, $sections, $requirements);
	}


	/**
	 * Every section as it is written, the oldest first: its maps, the entries without the version `from` says their fix
	 * is right from, and the requirements it turns on.
	 * @return array<string, array{array<string, array<string, mixed>>, array<string, string>}>  versions => maps, requirements
	 */
	public function getSections(): array
	{
		$sections = [];
		foreach (array_keys($this->since) as $versions) {
			if (isset($this->sections[$versions]) || isset($this->requirements[$versions])) {
				$sections[$versions] = [
					array_map(fn(array $entries) => array_map(self::removeFrom(...), $entries), $this->sections[$versions] ?? []),
					$this->requirements[$versions] ?? [],
				];
			}
		}

		return $sections;
	}


	/**
	 * The data for code that must work with the versions of the constraint, null where any version does. An entry is
	 * rewritten where its section holds for the lowest version, or the lowest version reaches the version `from` says
	 * its fix is right from, a section the line of the lowest version had later having the last word on it: every entry
	 * where any version does, none where there is no lower bound. A section that does not hold for the lowest version
	 * says nothing, the lowest version not taking its fix, and turns no requirement on. Every entry keeps the version of
	 * its section the line of the lowest version had it from, and the version `from`.
	 */
	public function selectFor(?string $constraint, Layer $layer): UpgradingData
	{
		$lowest = $constraint === null ? null : Versions::findLowestVersion($constraint);
		$holds = fn(Since $since) => $constraint === null || ($lowest !== null && $since->holdsFor($lowest));
		$reaches = fn(string $version) => $constraint === null || ($lowest !== null && version_compare($lowest, $version, '>='));
		$order = $this->since;
		uasort($order, fn(Since $a, Since $b) => version_compare($a->findVersionFor($lowest), $b->findVersionFor($lowest)));

		$maps = $retirements = $origins = $unreached = $requirements = [];
		foreach ($order as $versions => $since) {
			if ($holds($since)) {
				$requirements = array_replace($requirements, $this->requirements[$versions] ?? []);
			}

			$version = $since->findVersionFor($lowest);
			$pending = false;
			foreach ($this->sections[$versions] ?? [] as $map => $entries) {
				foreach ($entries as $key => $value) {
					$from = is_array($value) ? $value['from'] ?? null : null;
					if (!$holds($since) && ($from === null || !$reaches($from))) {
						$pending = true;
						continue;
					}

					$maps[$map][$key] = self::removeFrom($value);
					$origins[$map][$key] = [$version, $from];
					unset($retirements[$map][$key]);
					if (in_array($map, self::ForbiddenMaps, true) && $value !== MemberMapGrammar::Keep) {
						$retirements[$map][$key] = $version;
					}
				}
			}

			if ($pending) {
				$unreached[] = $since->findVersionAfter($lowest);
			}
		}

		$unreached = array_values(array_unique($unreached));
		usort($unreached, fn(string $a, string $b) => version_compare($a, $b));
		return new UpgradingData(
			$layer,
			$this->package,
			$this->namespaces,
			$maps,
			$retirements,
			$unreached,
			$requirements,
			$origins,
			$this->findNewestVersion(),
		);
	}


	/** The newest version a section is of. */
	private function findNewestVersion(): ?string
	{
		$newest = null;
		foreach ($this->since as $since) {
			if ($newest === null || version_compare($since->getNewest(), $newest, '>')) {
				$newest = $since->getNewest();
			}
		}

		return $newest;
	}


	/** The entry as the map reads it, without the version it is right from; `{write: X}` is X. */
	private static function removeFrom(mixed $value): mixed
	{
		if (!is_array($value) || !array_key_exists('from', $value)) {
			return $value;
		}

		unset($value['from']);
		return array_keys($value) === ['write'] ? $value['write'] : $value;
	}
}
