<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * How far a run of a plan got: what each fix changed, the files they failed on, and where and why the run stopped.
 * @internal
 */
final readonly class RunOutcome
{
	public function __construct(
		public RunStop $stop,
		/** the step the run stopped at; null where it is done */
		public ?int $step = null,
		/** @var array<int, array<string, list<string>>>  step => topic => the files its fix changed, relative to the root */
		public array $applied = [],
		/** @var list<string>  the tests failing after the step that passed before, none where they wrote no results */
		public array $newFailures = [],
		/** @var array<string, string>  the files the step could not fix, write or write back, relative to the root => why */
		public array $unprocessed = [],
		/** what refuses the target of the manual step the run stopped at */
		public ?Blockers $blockers = null,
		/** @var list<string>  the files the interrupted step may have rewritten, relative to the root */
		public array $interrupted = [],
		/** how the command of the tests crashed after the step though none of its tests failed */
		public ?string $crash = null,
		/** the results before the first step the last tests of the run were compared with; null where none ran */
		public ?TestResults $baseline = null,
		/** @var list<string>  the files the targets still find something in that a fix changes and no step of the plan did */
		public array $remaining = [],
		/** @var list<string>  what the run tells the user, an analysis that went without or upgrading data it lacked among it */
		public array $warnings = [],
	) {
	}


	/**
	 * 0 when the run is done, 1 when it leaves work for a human (tests that newly fail, what no step fixed, an
	 * interrupted run to put back or discard), 2 when it is incomplete, as files went unprocessed or the tests do not run
	 * before the first step, 4 when it waits for a step outside DressCode.
	 */
	public function getExitCode(): int
	{
		return match ($this->stop) {
			RunStop::Done => 0,
			RunStop::Tests => $this->step === null && $this->crash !== null ? 2 : 1,
			RunStop::Remaining, RunStop::Interrupted => 1,
			RunStop::Unprocessed => 2,
			RunStop::Composer, RunStop::Manual => 4,
		};
	}


	/** @param  list<string>  $warnings */
	public function withWarnings(array $warnings): self
	{
		return new self(...['warnings' => array_values(array_unique([...$this->warnings, ...$warnings]))] + get_object_vars($this));
	}
}
