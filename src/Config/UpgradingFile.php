<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{ConfigurationException, Profile};
use DressCode\Domains\Map;
use DressCode\Upgrading\{FunctionPattern, MemberMapGrammar, MemberPattern};
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
	/** the maps of what the code must not have, by which what a version above the lowest one retires is reported */
	private const ForbiddenMaps = ['forbiddenClasses', 'forbiddenFunctions', 'forbiddenConstants', 'forbiddenMembers'];

	private const SectionPattern = '~^since (\d+(?:\.\d+)*)$~D';
	private const PackagePattern = '~^(?:php|[a-z0-9_.-]+/[a-z0-9_.-]+)$~D';
	private const VersionPattern = '~^\d+(?:\.\d+)*$~D';


	private function __construct(
		/** the package the sections are versions of, `php` for PHP itself */
		public string $package,
		/** @var array{functions: list<string>, constants: list<string>}  what the package declares in its namespaces, fully qualified */
		public array $namespaces,
		/** @var array<string, array<string, array<string, mixed>>>  version => map => its entries, the oldest first */
		private array $sections,
		/** @var array<string, array<string, string>>  version => a requirement of the plugin of the package => its value */
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

		$sections = $requirements = [];
		foreach ($data as $key => $section) {
			if ($key === 'package' || $key === 'namespaces') {
				continue;
			} elseif (!is_string($key) || !preg_match(self::SectionPattern, $key, $m)) {
				throw new ConfigurationException("Upgrading file $label: Unexpected key `$key`; the file holds `package`, `namespaces` and sections `since <version>`.");
			} elseif (!is_array($section) || (array_is_list($section) && $section !== [])) {
				throw new ConfigurationException("Upgrading file $label: The section `$key` must be a map of maps to their entries.");
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

		uksort($sections, fn(string $a, string $b) => version_compare($a, $b));
		uksort($requirements, fn(string $a, string $b) => version_compare($a, $b));
		try {
			$namespaces = new Profile(namespaces: is_array($data['namespaces'] ?? null) ? $data['namespaces'] : [])->namespaces;
		} catch (\InvalidArgumentException $e) {
			throw new ConfigurationException("Upgrading file $label: {$e->getMessage()}", previous: $e);
		}

		return new self($package, $namespaces, $sections, $requirements);
	}


	/**
	 * Every section as it is written, the oldest first: its maps, the entries without the version `from` says their fix
	 * is right from, and the requirements it turns on.
	 * @return array<string, array{array<string, array<string, mixed>>, array<string, string>}>  version => maps, requirements
	 */
	public function getSections(): array
	{
		$versions = array_unique(array_map(strval(...), [...array_keys($this->sections), ...array_keys($this->requirements)]));
		usort($versions, fn(string $a, string $b) => version_compare($a, $b));
		$sections = [];
		foreach ($versions as $version) {
			$sections[$version] = [
				array_map(fn(array $entries) => array_map(self::removeFrom(...), $entries), $this->sections[$version] ?? []),
				$this->requirements[$version] ?? [],
			];
		}

		return $sections;
	}


	/**
	 * The data for code that must work with the versions of the constraint, null where any version does. An entry is
	 * rewritten where the lowest version reaches its section, or the version `from` says its fix is right from, a later
	 * section having the last word on it: every entry where any version does, none where there is no lower bound.
	 * What a section above the lowest version retires, where a version the constraint allows reaches it, is reported
	 * without a fix as the entry of a forbidden map (`retire()`), unless a section reached rewrites or forbids the same
	 * thing already. A requirement is turned on where the lowest version reaches its section, and says nothing above it.
	 * Every entry keeps the section and the version `from` it came with.
	 */
	public function selectFor(?string $constraint, Layer $layer): UpgradingData
	{
		$lowest = $constraint === null ? null : Versions::findLowestVersion($constraint);
		$reaches = fn(string $version) => $constraint === null || ($lowest !== null && version_compare($lowest, $version, '>='));
		$maps = $retirements = $origins = $retired = $unreached = $reported = [];
		foreach ($this->sections as $since => $section) {
			$since = (string) $since;
			$left = [];
			foreach ($section as $map => $entries) {
				foreach ($entries as $key => $value) {
					$from = is_array($value) ? $value['from'] ?? null : null;
					if (!$reaches($since) && ($from === null || !$reaches($from))) {
						$left[$map][$key] = $value;
						continue;
					}

					$maps[$map][$key] = self::removeFrom($value);
					$origins[$map][$key] = [$since, $from];
					unset($retirements[$map][$key]);
					if (in_array($map, self::ForbiddenMaps, true) && $value !== MemberMapGrammar::Keep) {
						$retirements[$map][$key] = $since;
					}
				}
			}

			if ($left === [] || $constraint === null) {
				continue;
			}

			$unreached[] = $since;
			if (!Versions::isSubset($constraint, "<$since")) {
				$reported[] = $since;
				foreach (self::retire($left) as [$map, $key, $sentence, $version]) {
					$retired[$map][$key] = [$sentence, $since, $version];
				}
			}
		}

		$written = [];
		foreach ($maps as $map => $entries) {
			foreach (array_keys($entries) as $key) {
				$target = self::findForbidden($map, (string) $key);
				if ($target !== null) {
					$written[$target[0]][self::identify(...$target)] = true;
				}
			}
		}

		foreach ($retired as $map => $entries) {
			foreach ($entries as $key => [$sentence, $since, $version]) {
				if (!isset($written[$map][self::identify($map, $key)])) {
					$maps[$map][$key] = $sentence;
					$origins[$map][$key] = [$since, $version];
					$retirements[$map][$key] = $version ?? $since;
				}
			}
		}

		$requirements = [];
		foreach ($this->requirements as $since => $values) {
			if ($reaches((string) $since)) {
				$requirements = array_replace($requirements, $values);
			}
		}

		return new UpgradingData(
			$layer,
			$this->package,
			$this->namespaces,
			$maps,
			$retirements,
			$unreached,
			$reported,
			$requirements,
			$origins,
			$this->findNewestVersion(),
		);
	}


	/** The newest version a section is of, the sections being in order of their versions. */
	private function findNewestVersion(): ?string
	{
		$newest = null;
		foreach ([array_key_last($this->sections), array_key_last($this->requirements)] as $version) {
			if ($version !== null && ($newest === null || version_compare((string) $version, $newest, '>'))) {
				$newest = (string) $version;
			}
		}

		return $newest;
	}


	/**
	 * What entries left above the lowest version say the code still meets on a version it may run on, as entries of the
	 * forbidden maps (`findForbidden()`), since what they write instead is not there in the lowest one: what they forbid,
	 * and what they replace, each with its sentence and the version it says, where that is not the one of its section.
	 * @param  array<string, array<string, mixed>>  $section  map => its entries
	 * @return list<array{string, string, ?string, ?string}>  forbidden map, key, sentence, version
	 */
	private static function retire(array $section): array
	{
		$retired = [];
		foreach ($section as $map => $entries) {
			foreach ($entries as $key => $value) {
				[$write, $from] = is_array($value) ? [$value['write'] ?? null, $value['from'] ?? null] : [$value, null];
				$target = self::findForbidden($map, (string) $key);
				if ($target === null || $write === MemberMapGrammar::Keep) {
					continue;
				}

				[$forbidden, $name] = $target;
				$retired[] = match (true) {
					$map === $forbidden => [$forbidden, $name, $write, null],
					$write === MemberMapGrammar::Removed => [$forbidden, $name, 'does nothing', $from],
					is_string($write) => [$forbidden, $name, 'replaced by `' . ltrim($write, '\\') . '`', null],
					default => [$forbidden, $name, 'replaced', null],
				};
			}
		}

		return $retired;
	}


	/**
	 * The forbidden map an entry of the map is reported by where it cannot be rewritten, and its key there: a call of
	 * a function as the function, a call of a member by the shape of its arguments as `forbiddenMembers` reads it; null
	 * for a map that forbids nothing by itself, an annotation and the maps of plugins, and for a key that is no name.
	 * @return ?array{string, string}
	 */
	private static function findForbidden(string $map, string $key): ?array
	{
		try {
			return match (true) {
				in_array($map, self::ForbiddenMaps, true) => [$map, $key],
				$map === 'replacedClasses' => ['forbiddenClasses', $key],
				$map === 'replacedFunctions' => ['forbiddenFunctions', $key],
				$map === 'replacedMembers' => ['forbiddenMembers', $key],
				$map === 'replacedCalls' && MemberMapGrammar::isFunctionKey($key)
					=> ['forbiddenFunctions', FunctionPattern::fromKey($key)->name],
				$map === 'replacedCalls' => ['forbiddenMembers', $key],
				default => null,
			};
		} catch (\InvalidArgumentException) {
			return null; // the grammar of the map says what is wrong with the key
		}
	}


	/**
	 * What the key of a forbidden map names whatever way it is written, so that one rewritten and one forbidden meet: a
	 * member by its class and name, a class, a function and a constant by its name.
	 */
	private static function identify(string $forbidden, string $key): string
	{
		try {
			$member = $forbidden === 'forbiddenMembers' ? MemberPattern::fromKey($key) : null;
		} catch (\InvalidArgumentException) {
			$member = null;
		}

		return strtolower($member === null ? ltrim($key, '\\') : "$member->class::$member->name");
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
