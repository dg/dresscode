<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;


/**
 * The names of the native types, as a doc comment may write them.
 * @internal
 */
final class NativeType
{
	/** the other names PHP gives a scalar type, which a doc comment may write */
	public const Synonyms = ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float'];
}
