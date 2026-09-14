<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;


/**
 * What one upgrading file says to the project: the maps of the sections the version the project stands on reaches,
 * and what the package declares in its namespaces.
 * @internal
 */
final readonly class UpgradingData
{
	public function __construct(
		/** the file and the package shipping it */
		public Layer $layer,
		/** @var array{functions: list<string>, constants: list<string>}  what the package declares in its namespaces, fully qualified */
		public array $namespaces,
		/** @var array<string, array<string, mixed>>  map => its entries */
		public array $maps,
	) {
	}
}
