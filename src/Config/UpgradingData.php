<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Engine\Helpers;
use function is_string;


/**
 * What one upgrading file, or what DressCode itself knows of a package, says to the project: the maps of the sections
 * the lowest version the code must work with reaches, those of the later ones it may run on as what they retire, the
 * rules of the plugin of the package it turns on, and what the package declares in its namespaces.
 * @internal
 */
final readonly class UpgradingData
{
	public function __construct(
		/** the file and the package shipping it */
		public Layer $layer,
		/** the package whose versions the sections of the file are of */
		public string $package,
		/** @var array{functions: list<string>, constants: list<string>}  what the package declares in its namespaces, fully qualified */
		public array $namespaces,
		/** @var array<string, array<string, mixed>>  map => its entries, as the file writes them */
		public array $maps,
		/** @var array<string, array<string, string>>  forbidden map => the key of an entry => the version that retired it */
		public array $retirements = [],
		/** @var list<string>  the versions of the sections holding what is not rewritten yet, ascending */
		public array $unreached = [],
		/**
		 * @var list<string>  the versions of those sections a version the code may run on reaches, what they retire being
		 * reported, ascending
		 */
		public array $reported = [],
		/** @var array<string, string>  the requirements of the rules of the plugin of the package it turns on => their values */
		public array $requirements = [],
		/**
		 * @var array<string, array<string, array{string, ?string}>>  map => the key of an entry => the version of the section
		 * it came with and the version `from` says its fix is right from
		 */
		public array $origins = [],
		/** the newest version a section of the file is of, null for what DressCode knows of a package itself */
		public ?string $newest = null,
	) {
	}


	/**
	 * The maps and the requirements as the decisions of layers, each under the path the closure gives it and left out
	 * where it gives none: the entries in the layer of the section they came with, the requirements in the layer of the
	 * file, the sentence of a retired entry ending with the package and the version that retired it
	 * (`, since acme/lib 3.0`). Every entry stands in one layer, so their order decides nothing.
	 * @param  \Closure(string): ?string  $findPath  the map or the requirement => the path of its decision
	 * @return list<array{Layer, array<string, mixed>}>
	 */
	public function buildDecisions(\Closure $findPath): array
	{
		$package = $this->package === PackageDiscovery::Php ? 'PHP' : $this->package;
		$layers = $trees = [];
		foreach ($this->maps as $map => $entries) {
			$path = $findPath($map);
			if ($path === null) {
				continue;
			}

			foreach ($this->retirements[$map] ?? [] as $key => $version) {
				$sentence = $entries[$key];
				$entries[$key] = (is_string($sentence) ? rtrim($sentence, '.') . ', ' : '') . "since $package $version";
			}

			$bySection = [];
			foreach ($entries as $key => $value) {
				[$since, $from] = $this->origins[$map][$key] ?? [null, null];
				$layers["$since|$from"] ??= $since === null ? $this->layer : $this->layer->withSection($this->package, $since, $from);
				$bySection["$since|$from"][$key] = $value;
			}

			foreach ($bySection as $id => $section) {
				$trees[$id] = Helpers::placeValue($trees[$id] ?? [], $path, $section);
			}
		}

		foreach ($this->requirements as $requirement => $value) {
			$path = $findPath($requirement);
			if ($path !== null) {
				$layers['|'] ??= $this->layer;
				$trees['|'] = Helpers::placeValue($trees['|'] ?? [], $path, $value);
			}
		}

		return array_map(fn(string|int $id) => [$layers[$id], $trees[$id]], array_keys($trees));
	}
}
