<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\{RunInfo, RunResult};
use DressCode\{FileResult, Reporter};


/**
 * The reporter of the fix of a topic, which says nothing and writes nothing: it collects what the fix would change,
 * what it leaves for a human and the files it could not process.
 * @internal
 */
final class TopicReporter implements Reporter
{
	/** @var array<string, array{string, string}>  the files the fix changes, relative to the root => the text before and after */
	public private(set) array $changed = [];

	/** @var list<Task> */
	public private(set) array $tasks = [];

	/** @var array<string, string>  the files the fix could not process, relative to the root => why */
	public private(set) array $failed = [];


	public function start(RunInfo $run): void
	{
	}


	public function reportFile(FileResult $result): void
	{
		if ($result->syntaxError !== null || $result->failure !== null) {
			$this->failed[$result->path] = $result->failure ?? "Syntax error: $result->syntaxError";
			return;
		} elseif ($result->changed && $result->output !== null) {
			$this->changed[$result->path] = [$result->code, $result->output];
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
