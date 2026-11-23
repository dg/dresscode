<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\ProjectPackages;


/**
 * Where the saved plan stands without a fix run: whether each step of Composer and each manual step is done, and what
 * a run keeps to go back, the steps it did not finish, the raise it journals and the step it left uncommitted.
 * @internal
 */
final readonly class PlanStatus
{
	private function __construct(
		/** @var array<int, StepStatus>  number of the step => what is known of it */
		public array $steps,
		/** @var array<int, list<string>>  step => the files a run interrupted in it may have rewritten */
		public array $interrupted,
		/** the step whose raise the journal can go back before; null where it keeps none */
		public ?int $raisedStep,
		/** @var list<string>  the files of Composer that raise changed and are not put back yet */
		public array $raisedFiles,
		/** the files of Composer were put back, and the packages of the lock are still to be installed */
		public bool $awaitingInstall,
		/** the step a run with `--commit` left uncommitted; null where none */
		public ?int $uncommittedStep,
		/** @var array<string, ?int>  command of the tests => when its baseline ran, null where it has none yet */
		public array $baselines,
	) {
	}


	/** Reads the plan against the project and what a run keeps, asking Composer only what the lock refuses. */
	public static function read(Plan $plan, Project $project): self
	{
		$state = new RunState($project);
		$packages = ProjectPackages::read($project->root);
		$composer = Composer::forProject($project->root);
		$steps = [];
		foreach ($plan->steps as $index => $step) {
			$steps[$index + 1] = match (true) {
				$step->skip => StepStatus::Skipped,
				$step instanceof ComposerStep => $step->isDoneIn($packages) ? StepStatus::Done : StepStatus::Due,
				$step instanceof ManualStep => self::findManualStatus($step, $composer),
				default => StepStatus::Unknown,
			};
		}

		$baselines = [];
		foreach ($plan->listTestCommands() as $command) {
			$baselines[$command] = $state->findBaseline($command)?->time;
		}

		return new self(
			$steps,
			$state->snapshots->findInterrupted(),
			$state->journal->findStep(),
			$state->journal->findChanged(),
			$state->journal->isAwaitingInstall(),
			$state->findUncommitted()?->step,
			$baselines,
		);
	}


	private static function findManualStatus(ManualStep $step, PackageManager $composer): StepStatus
	{
		$blockers = $composer->findBlockers($step->target);
		return match (true) {
			$blockers->unknown !== null => StepStatus::Unknown,
			$blockers->isClear() => StepStatus::Done,
			default => StepStatus::Due,
		};
	}
}
