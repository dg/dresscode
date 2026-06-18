<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Reporters;

use DressCode\{FileResult, Reporter, RunResult};


/**
 * Reports nothing; for runs whose result is consumed by code.
 */
final class NullReporter implements Reporter
{
	public function start(int $fileCount, bool $fix): void
	{
	}


	public function reportFile(FileResult $result): void
	{
	}


	public function finish(RunResult $result): void
	{
	}
}
