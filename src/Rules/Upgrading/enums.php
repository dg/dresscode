<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;


/**
 * What an entry of the upgrading data of PHP does with a call it matches.
 * @internal
 */
enum UpgradingOperation
{
	/** the call is reported, nothing taking its place */
	case Report;

	/** the call does nothing and goes, with the statement it makes */
	case Remove;
}
