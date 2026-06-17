<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;


/**
 * Where the PHP version the rules target was taken from.
 * @internal
 */
enum PhpVersionSource
{
	case Configuration;
	case Composer;
	case Default;
}
