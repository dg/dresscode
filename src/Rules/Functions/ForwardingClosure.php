<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use function in_array;


/**
 * What the rules writing a callable ask of a name in it.
 * @internal
 */
final class ForwardingClosure
{
	/** Whether the name is one the scope gives its meaning to, which a callable resolves at once. */
	public static function isScopeRelative(string $name): bool
	{
		return in_array(strtolower(ltrim($name, '\\')), ['self', 'parent', 'static'], true);
	}
}
