<?php declare(strict_types=1);

namespace DressCode;

use DressCode\Engine\FileResult;
use DressCode\Engine\RunResult;


/**
 * Presents the results of a run; files arrive one by one in the order of the input, the summary at the end.
 */
interface Reporter
{
	public function start(int $fileCount, bool $fix): void;

	public function reportFile(FileResult $result): void;

	public function finish(RunResult $result): void;
}
