<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;


/**
 * An entry of the upgrading data of PHP as the rule works with it: the version that retired the call.
 * @internal
 */
final readonly class UpgradingEntry
{
	public function __construct(
		/** the version of the section, which retired the call */
		public string $retiredIn,
	) {
	}
}
