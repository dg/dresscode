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


/**
 * Why a file of the project is left out of the scope `dresscode migrate config` writes.
 * @internal
 */
enum LeftOutReason: string
{
	/** `linguist-generated` of `.gitattributes` says a generator wrote it */
	case Generated = 'generated';

	/** a test in the format PHPT, sections of text around the code, which is no PHP */
	case PhptTest = 'PHPT test';

	/** it lies in a directory of the data of tests, whose style is the one a test needs */
	case TestData = 'test data';

	/** git ignores it, a build or a local file */
	case Ignored = 'ignored by git';

	/** the configuration of the formatter of the project leaves it out */
	case Foreign = 'left out by your formatter';
}
