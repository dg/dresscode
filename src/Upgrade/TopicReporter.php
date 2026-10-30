<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\{RunInfo, RunResult};
use DressCode\{FileResult, Reporter};


/**
 * The reporter of the fix of a topic, which says nothing: it tells `$keep` every file the fix changes as it was before,
 * and collects what the fix changed and what it leaves for a human.
 * @internal
 */
final class TopicReporter implements Reporter
{
	/** @var array<string, string>  the files the fix changed, relative to the root => the text it left */
	public private(set) array $changed = [];

	/** @var list<Task> */
	public private(set) array $tasks = [];


	public function __construct(
		/** @var ?\Closure(string, string): void  given a file the fix changes, relative to the root, and its text before */
		private readonly ?\Closure $keep = null,
	) {
	}


	public function start(RunInfo $run): void
	{
	}


	public function reportFile(FileResult $result): void
	{
		if ($result->changed && $result->output !== null) {
			$this->keep?->__invoke($result->path, $result->code);
			$this->changed[$result->path] = $result->output;
		}

		foreach ($result->remaining as $violation) {
			if (!$violation->fixable || $violation->refused) {
				$this->tasks[] = new Task($result->path, $violation);
			}
		}
	}


	public function finish(RunResult $result): void
	{
	}
}
