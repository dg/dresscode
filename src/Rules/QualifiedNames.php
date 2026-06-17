<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;


/**
 * The last segment of a fully qualified name of a class, a function or a constant.
 */
final class QualifiedNames
{
	/** The last segment of a fully qualified name, `Order` of `Acme\Shop\Order`. */
	public static function stripNamespace(string $name): string
	{
		return substr($name, (int) strrpos('\\' . $name, '\\'));
	}
}
