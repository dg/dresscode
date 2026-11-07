<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;


/**
 * What a note of a translation says of a foreign rule.
 * @internal
 */
enum NoteKind: string
{
	/** carried over, but DressCode does it differently */
	case Difference = 'difference';

	/** the project has to settle it by hand */
	case Request = 'request';

	/** no DressCode rule stands for it */
	case Uncovered = 'uncovered';
}
