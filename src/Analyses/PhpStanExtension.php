<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * An extension of PHPStan a package of the project brings.
 * @internal
 */
final readonly class PhpStanExtension
{
	public function __construct(
		public string $package,
		/** as Composer writes it, `2.2.2`, or `dev-master 29ca070` for a branch */
		public string $version,
		/** the directory the package is installed in */
		public string $path,
		/** @var list<string>  the configuration files PHPStan includes by itself */
		public array $includes,
	) {
	}
}
