<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\WorkerCodec;
use function is_array, is_int;


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
		public array $applied = [],
		/** @var array<string, list<Task>>  topic => what its last fix left */
		public array $tasks = [],
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
		/** @var list<string>  the files of Composer a raise changed, which `upgrade abort` puts back */
		public array $raisedFiles = [],
		/** @var list<string>  the files the targets still find something in that a fix changes and no step of the plan did */
		public array $remaining = [],
		/** @var list<Postponement>  the comments that put a place of the upgrade off without a reason */
		public array $postponed = [],
		/** @var list<string>  what the run tells the user, an analysis that went without or upgrading data it lacked among it */
		public array $warnings = [],
		/** why Composer could not raise the constraints of the step the run stopped at; null where it was not asked or did */
		public ?string $composerFailure = null,
		/** why what the step changed is left uncommitted; null where it was committed or was not to be */
		public ?string $commitFailure = null,
	) {
	}


	/**
	 * 0 when the run is done, 1 when it leaves work for a human (tasks, tests that newly fail, what no step fixed, an
	 * interrupted run to put back or discard), 2 when it is incomplete, as files went unprocessed, a commit was refused or
	 * the tests do not run before the first step, 4 when it waits for a step outside DressCode.
	 */
	public function getExitCode(): int
	{
		return match ($this->stop) {
			RunStop::Done => 0,
			RunStop::Tests => $this->step === null && $this->crash !== null ? 2 : 1,
			RunStop::Tasks, RunStop::Remaining, RunStop::Interrupted => 1,
			RunStop::Unprocessed, RunStop::Commit => 2,
			RunStop::Composer, RunStop::Raised, RunStop::Manual => 4,
		};
	}


	/** @param  list<string>  $warnings */
	public function withWarnings(array $warnings): self
	{
		return new self(...['warnings' => array_values(array_unique([...$this->warnings, ...$warnings]))] + get_object_vars($this));
	}


	/**
	 * A run stopped after a raise as the run that goes on after it reads it in another process.
	 * @param  array<string, mixed>  $data  as `toArray()` made it
	 */
	public static function fromArray(array $data): self
	{
		$applied = $tasks = [];
		foreach (is_array($data['applied'] ?? null) ? $data['applied'] : [] as $step => $topics) {
			foreach (is_array($topics) ? $topics : [] as $topic => $files) {
				$applied[(int) $step][(string) $topic] = array_values(array_map(strval(...), (array) $files));
			}
		}

		foreach (is_array($data['tasks'] ?? null) ? $data['tasks'] : [] as $topic => $list) {
			$paths = array_map(strval(...), array_column((array) $list, 'path'));
			$violations = WorkerCodec::decodeViolations(array_column((array) $list, 'violation'));
			$tasks[(string) $topic] = array_map(fn(string $path, $violation) => new Task($path, $violation), $paths, $violations);
		}

		return new self(RunStop::Raised, is_int($data['step'] ?? null) ? $data['step'] : null, $applied, $tasks);
	}


	/**
	 * What a run that goes on after the raise needs of this one: where it stopped, what the fixes changed and what they
	 * left.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'step' => $this->step,
			'applied' => $this->applied,
			'tasks' => array_map(fn(array $list) => array_map(fn(Task $task) => [
				'path' => $task->path,
				'violation' => WorkerCodec::encodeViolation($task->violation),
			], $list), $this->tasks),
		];
	}
}
