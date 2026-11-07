<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * One step of a plan, a recipe rather than its result, which the run measures anew whenever it comes to it.
 * @internal
 */
interface Step
{
	public string $title { get; }

	/** the user left it out */
	public bool $skip { get; }
}
