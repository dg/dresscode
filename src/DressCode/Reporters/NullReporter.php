<?php declare(strict_types=1);

namespace DressCode\Reporters;

use DressCode\Engine\FileResult;
use DressCode\Engine\RunResult;
use DressCode\Reporter;


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
