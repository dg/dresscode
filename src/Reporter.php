<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use DressCode\Engine\{RunInfo, RunResult};


/**
 * Presents the results of a run; files arrive one by one in the order of the input, the summary at the end.
 * @internal
 */
interface Reporter
{
	function start(RunInfo $run): void;

	function reportFile(FileResult $result): void;

	function finish(RunResult $result): void;
}
