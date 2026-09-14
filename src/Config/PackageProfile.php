<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Profile;


/**
 * What one upgrading file says to the project: the options of the sections the version the project stands on reaches.
 * @internal
 */
final readonly class PackageProfile
{
	public function __construct(
		/** the file and the package shipping it, as a message names them */
		public string $source,
		/** the package whose versions the sections of the file are of */
		public string $package,
		public Profile $profile,
		/** @var list<string>  the versions of the sections left out as not reached yet, ascending */
		public array $unreached = [],
	) {
	}
}
