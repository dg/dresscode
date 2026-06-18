<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * Presents the results of a run; files arrive one by one in the order of the input, the summary at the end.
 */
interface Reporter
{
	public function start(int $fileCount, bool $fix): void;

	public function reportFile(FileResult $result): void;

	public function finish(RunResult $result): void;
}
