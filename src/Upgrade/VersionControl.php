<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * What a run of the plan asks of the version control of the project, which a test replaces.
 * @internal
 */
interface VersionControl
{
	/**
	 * Whether the tracked files have changes, the files given aside.
	 * @param  list<string>  $except  relative to the root
	 */
	function readStatus(array $except = []): WorkingTree;
}
