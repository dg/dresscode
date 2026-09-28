<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;


/**
 * Whether a run has the types of the code.
 * @internal
 */
enum TypeAnalysisStatus
{
	/** the run has them */
	case Enabled;

	/** the project can turn them on, PHPStan being installed */
	case Available;

	/** PHPStan is not installed */
	case Unavailable;
}
