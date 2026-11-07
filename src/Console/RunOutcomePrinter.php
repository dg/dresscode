<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Engine\Helpers;
use DressCode\Upgrade\{Blocker, ComposerStep, DependencyStep, ManualStep, Plan, PlanFile, RunOutcome, RunStop, Step, UpdateStep};
use Nette\CommandLine\Console;
use function count;


/**
 * Writes how far a run of a plan got: the steps for a human it passed, what each fix changed, and where and why it
 * stopped.
 * @internal
 */
final readonly class RunOutcomePrinter
{
	/** the version of the document the format `json` writes for a run */
	public const Version = 1;


	public function __construct(
		private Plan $plan,
		private RunOutcome $outcome,
		/** the step the run was narrowed to */
		private ?int $singleStep = null,
	) {
	}


	public function print(Console $console): string
	{
		$outcome = $this->outcome;
		$out = '';
		foreach ($outcome->applied as $number => $topics) {
			$out .= Markup::formatLine($console, "Step $number", implode(', ', array_map(
				fn(string $topic, array $files) => "$topic "
					. ($files === [] ? 'left nothing to fix' : Helpers::formatCount(count($files), 'file')),
				array_keys($topics),
				$topics,
			))) . "\n";
		}

		if ($outcome->unprocessed !== []) {
			$out .= Markup::formatLine(
				$console,
				'Unverified',
				Helpers::formatCount(count($outcome->unprocessed), 'file') . ' the fixes could not process, left as they are',
			) . "\n";
			foreach ($outcome->unprocessed as $path => $why) {
				$out .= "  $path  " . Markup::highlightCode($console, (string) strtok($why, "\n")) . "\n";
			}
		}

		if ($baseline = $outcome->baseline) {
			// a test failing before the first step is no regression, so passing the comparison is not passing the tests
			$out .= Markup::formatLine(
				$console,
				'Tests',
				'compared with their run before the first step'
				. ($baseline->time === null ? '' : ', ' . date('Y-m-d H:i', $baseline->time))
				. ', where ' . $baseline->countNotPassed() . ' of ' . count($baseline->tests) . ' did not pass',
			) . "\n";
		}

		foreach ($outcome->warnings as $warning) {
			$out .= Markup::formatLine($console, 'Warning', $warning) . "\n";
		}

		if ($outcome->raisedFiles !== []) {
			$out .= Markup::formatLine(
				$console,
				'Raised',
				implode(', ', $outcome->raisedFiles) . ' changed',
				', which `dresscode upgrade abort` puts back as they were before the raise and installs',
			) . "\n";
		}

		return $out . match ($outcome->stop) {
			RunStop::Tests => Markup::formatLine($console, 'Failed', match (true) {
				$outcome->step === null => "$outcome->crash, so nothing was changed",
				$this->plan->steps[$outcome->step - 1] instanceof DependencyStep
					=> "step $outcome->step raised the dependencies, which stay raised, and ",
				default => "step $outcome->step went back, its fixes undone and the dependencies as the steps before left them, as ",
			} . match (true) {
				$outcome->step === null => '',
				$outcome->newFailures === [] => $outcome->crash ?? 'the tests wrote no results after it',
				default => 'these tests now fail: ' . implode(', ', $outcome->newFailures),
			}),
			RunStop::Composer => $outcome->composerFailure === null
				? Markup::formatLine($console, 'Waiting', "step $outcome->step, {$this->formatComposerStep((int) $outcome->step)}")
				: Markup::formatLine(
					$console,
					'Failed',
					"step $outcome->step, $outcome->composerFailure; {$this->formatComposerStep((int) $outcome->step)}",
				),
			RunStop::Raised => Markup::formatLine(
				$console,
				'Waiting',
				"step $outcome->step raised the dependencies",
				', which what runs after it loads anew',
				': run `dresscode upgrade run` again',
			),
			RunStop::Manual
				=> Markup::formatLine($console, 'Waiting', "step $outcome->step, {$this->formatManualStep((int) $outcome->step)}"),
			RunStop::Interrupted => $outcome->interrupted === []
				? Markup::formatLine(
					$console,
					'Failed',
					"step $outcome->step did not finish and may have rewritten files its snapshots no longer tell",
					', which `git status` and `git diff` show',
					', or go on from the code as it is with `dresscode upgrade run --discard`',
				)
				: Markup::formatLine(
					$console,
					'Failed',
					"step $outcome->step did not finish, and may have rewritten " . implode(', ', $outcome->interrupted)
					. ': `dresscode upgrade abort` puts them back as they were before it'
					. ', or go on from the code as it is with `dresscode upgrade run --discard`',
				),
			RunStop::Unprocessed => Markup::formatLine(
				$console,
				'Waiting',
				"step $outcome->step did not fix the files above: make each parse, or leave it out of the paths, then run `dresscode upgrade run`",
			),
			RunStop::Remaining => Markup::formatLine(
				$console,
				'Waiting',
				'the targets still find what no step of the plan fixed'
				. ($outcome->remaining === []
					? ', listed above'
					: ', which `dresscode upgrade today` fixes in ' . implode(', ', $outcome->remaining))
				. ', then run `dresscode upgrade run`',
			),
			RunStop::Done => $console->color('white/green', $this->singleStep === null
				? 'UPGRADED  ' . $this->formatDoneSteps() . ', and the final check finds nothing more to upgrade'
					. (($this->plan->testCommand ?? $this->plan->quickTestCommand) === null ? ', no tests run' : '')
					. ($this->plan->missingData === []
						? ''
						: ', though without the upgrading data of '
							. implode(', ', array_keys($this->plan->missingData))
							. ', which the estimate lacked')
				: "DONE      step $this->singleStep")
				. ($this->singleStep === null && $this->leavesLowest()
					? "\n" . Markup::highlightCode(
						$console,
						'A raise of the plan installs the lowest versions the constraints allow, which the code is written for; `composer update` installs the newest.',
					)
					: ''),
		} . "\n";
	}


	/**
	 * The run as the data the format `json` writes beside the plan.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$outcome = $this->outcome;
		$applied = [];
		foreach ($outcome->applied as $step => $topics) {
			foreach ($topics as $topic => $files) {
				$applied[] = ['step' => $step, 'topic' => $topic, 'files' => $files];
			}
		}

		return [
			'stop' => lcfirst($outcome->stop->name),
			'step' => $outcome->step,
			'applied' => $applied,
			'newFailures' => $outcome->newFailures,
			'crash' => $outcome->crash,
			'baseline' => $outcome->baseline === null ? null : [
				'time' => $outcome->baseline->time === null ? null : date(DATE_ATOM, $outcome->baseline->time),
				'tests' => count($outcome->baseline->tests),
				'notPassed' => $outcome->baseline->countNotPassed(),
			],
			'unverified' => array_map(
				fn(string $path, string $why) => ['file' => $path, 'message' => $why],
				array_keys($outcome->unprocessed),
				$outcome->unprocessed,
			),
			'blockers' => $outcome->blockers === null ? null : [
				'packages' => array_map(fn(Blocker $blocker) => (array) $blocker, $outcome->blockers->packages),
				'unknown' => $outcome->blockers->unknown,
			],
			'interrupted' => $outcome->interrupted,
			'raisedFiles' => $outcome->raisedFiles,
			'remaining' => $outcome->remaining,
			'warnings' => $outcome->warnings,
			'composerFailure' => $outcome->composerFailure,
		];
	}


	/** The steps of the plan that ran and those it skips: "3 steps done, 1 skipped as the plan says". */
	private function formatDoneSteps(): string
	{
		$done = count($this->plan->findSteps());
		$skipped = count($this->plan->steps) - $done;
		return Helpers::formatCount($done, 'step') . ' done' . ($skipped ? ", $skipped skipped as the plan says" : '');
	}


	/**
	 * Whether a step of the plan updates the packages, which it leaves at the lowest versions the constraints allow, and
	 * no step of it updates them to the newest then.
	 */
	private function leavesLowest(): bool
	{
		$steps = $this->plan->findSteps();
		return array_any($steps, fn(Step $step) => $step instanceof ComposerStep && $step->update)
			&& !array_any($steps, fn(Step $step) => $step instanceof UpdateStep);
	}


	private function formatComposerStep(int $number): string
	{
		$step = $this->plan->steps[$number - 1];
		assert($step instanceof DependencyStep);
		return "$step->title: run `" . implode('` and `', $step->formatCommands()) . '`, then `dresscode upgrade run`';
	}


	private function formatManualStep(int $number): string
	{
		$step = $this->plan->steps[$number - 1];
		assert($step instanceof ManualStep && $this->outcome->blockers !== null);
		return "make way for {$step->target->format()}, " . EstimatePrinter::formatBlockers($this->outcome->blockers)
			. ($this->outcome->blockers->unknown === null
				? ": update or replace what refuses it, as `{$step->formatCommand()}` tells"
				: ': where you know nothing refuses it, set `skip: true` on the step in `' . PlanFile::Name . '`')
			. ', then run `dresscode upgrade run`';
	}
}
