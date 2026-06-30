<?php declare(strict_types=1);

namespace DressCode\Reporters;

use DressCode\FileResult;
use DressCode\Reporter;
use DressCode\RunResult;


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
