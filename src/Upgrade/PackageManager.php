<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * What a run of the plan asks of the packages of the project, which a test replaces to make Composer refuse where it
 * wants.
 * @internal
 */
interface PackageManager
{
	function findBlockers(Target $target): Blockers;
}
