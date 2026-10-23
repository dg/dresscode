<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;


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
}
