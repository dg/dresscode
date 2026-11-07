<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\{RunInfo, RunResult};
use DressCode\{FileResult, Reporter};


/**
 * The reporter of the fix of a topic, which says nothing and writes nothing: it collects what the fix would change and
 * the files it could not process.
 * @internal
 */
final class TopicReporter implements Reporter
{
	/** @var array<string, array{string, string}>  the files the fix changes, relative to the root => the text before and after */
	public private(set) array $changed = [];

	/** @var array<string, string>  the files the fix could not process, relative to the root => why */
	public private(set) array $unprocessed = [];


	public function __construct(
		/** @var list<string>  what the configuration of the fix and its run tell the user, the analyses that went without among it */
		public private(set) array $warnings = [],
	) {
	}


	public function start(RunInfo $run): void
	{
	}


	public function reportFile(FileResult $result): void
	{
		if ($result->syntaxError !== null || $result->failure !== null) {
			$this->unprocessed[$result->path] = $result->failure ?? "Syntax error: $result->syntaxError";
			return;
		} elseif ($result->changed && $result->output !== null) {
			$this->changed[$result->path] = [$result->code, $result->output];
		}
	}


	public function finish(RunResult $result): void
	{
		$this->warnings = array_values(array_unique([...$this->warnings, ...$result->warnings]));
	}
}
