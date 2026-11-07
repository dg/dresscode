<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Reporters;

use DressCode\Engine\{RunInfo, RunResult};
use DressCode\{FileResult, Reporter, Violation};


/**
 * Keeps what the files violate, of which the result of a run holds only the counts; for runs whose violations are
 * consumed by code.
 * @internal
 */
final class CollectingReporter implements Reporter
{
	/** @var array<string, list<Violation>>  the path => what the code of the file violates, a clean file left out */
	public private(set) array $violations = [];


	public function start(RunInfo $run): void
	{
	}


	public function reportFile(FileResult $result): void
	{
		if ($result->violations !== []) {
			$this->violations[$result->path] = $result->violations;
		}
	}


	public function finish(RunResult $result): void
	{
	}
}
