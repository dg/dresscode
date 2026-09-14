<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Profile, RuleGroup};


/**
 * What one upgrading file says to the project: the options of the sections the version the project stands on reaches,
 * the intent of them, the group that turns on the rules they feed, and what the package declares in its namespaces.
 * @internal
 */
final readonly class PackageProfile
{
	public function __construct(
		/** the file and the package shipping it, as a message names them */
		public string $source,
		public Profile $profile,
		public RuleGroup $group,
	) {
	}
}
