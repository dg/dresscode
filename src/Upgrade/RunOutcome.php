<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * How far a run of a plan got: what each fix changed, the tasks the fixes left, the files they failed on, and where and
 * why the run stopped.
 * @internal
 */
final readonly class RunOutcome
{
	public function __construct(
		public RunStop $stop,
		/** the step the run stopped at; null where it is done */
		public ?int $step = null,
		/** @var array<int, array<string, list<string>>>  step => topic => the files its fix changed, relative to the root */
		public array $fixed = [],
		/** @var array<string, list<Task>>  topic => what its last fix left */
		public array $tasks = [],
		/** @var list<string>  the tests failing after the step that passed before, none where they wrote no results */
		public array $failures = [],
		/** @var array<string, string>  the files the step could not fix, write or write back, relative to the root => why */
		public array $errors = [],
	) {
	}
}
