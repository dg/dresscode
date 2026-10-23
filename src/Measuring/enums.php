<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;


/**
 * What the code says of a decision measured under its values.
 * @internal
 */
enum Classification
{
	/** one value holds in at least the threshold of the places, and there are enough of them */
	case Settled;

	/** few places, all of one value */
	case SettledWeakly;

	/** no value alone reaches the threshold, the values the code has together do, and the decision takes a tolerance */
	case Tolerated;

	/** the code writes it in several ways */
	case Mixed;

	/** no place of the decision */
	case Absent;
}
