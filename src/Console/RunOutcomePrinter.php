<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Upgrade\{Plan, RunOutcome, RunStop, Step, StepKind};
use Nette\CommandLine\Console;
use function count;


/**
 * Writes how far a run of a plan got: the steps for a human it passed, what each fix changed, the tasks left and
 * where and why it stopped.
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
		private ?int $only = null,
	) {
	}


	public function print(Console $console): string
	{
		$outcome = $this->outcome;
		$out = '';
		foreach ($this->plan->findSteps() as $number => $step) {
			if (
				$step->kind === StepKind::Manual
				&& ($outcome->step === null || $number < $outcome->step)
				&& ($this->only ?? $number) === $number
			) {
				$out .= Markup::formatLabel($console, "Step $number")
					. "for you, $step->title"
					. ($step->suggest === null ? '' : ": `$step->suggest`")
					. "\n";
			}
		}

		foreach ($outcome->fixed as $number => $topics) {
			$out .= Markup::formatLabel($console, "Step $number") . implode(', ', array_map(
				fn(string $topic, array $files) => "$topic "
					. ($files === [] ? 'left nothing to fix' : Markup::formatCount(count($files), 'file')),
				array_keys($topics),
				$topics,
			)) . "\n";
		}

		$out .= new TasksPrinter($outcome->tasks)->print($console);
		return $out . match ($outcome->stop) {
			RunStop::Tests => Markup::formatLabel($console, 'Failed') . "step $outcome->step went back, as " . ($outcome->failures === []
				? 'the tests wrote no results after it'
				: 'these tests now fail: ' . implode(', ', $outcome->failures)),
			RunStop::Composer
				=> Markup::formatLabel($console, 'Waiting') . "step $outcome->step, {$this->formatComposerStep((int) $outcome->step)}",
			RunStop::Tasks => Markup::formatLabel($console, 'Waiting')
				. "step $outcome->step leaves the places above: fix each or confirm it with "
				. '`// dresscode:ignore <decision> -- <reason>`, then run `dresscode upgrade --continue`',
			RunStop::Done => $console->color('white/green', $this->only === null
				? 'UPGRADED  every step of the plan is done'
				: "DONE      step $this->only")
				. ($this->only === null && $this->updatesPackages()
					? "\nThe packages are at the lowest versions the constraints allow, which the code is written for; `composer update` installs the newest."
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
		$fixed = [];
		foreach ($outcome->fixed as $step => $topics) {
			foreach ($topics as $topic => $files) {
				$fixed[] = ['step' => $step, 'topic' => $topic, 'files' => $files];
			}
		}

		return [
			'stop' => lcfirst($outcome->stop->name),
			'step' => $outcome->step,
			'fixed' => $fixed,
			'tasks' => new TasksPrinter($outcome->tasks)->toArray()['tasks'],
			'failures' => $outcome->failures,
		];
	}


	/** Whether a step of the plan updates the packages, which it leaves at the lowest versions the constraints allow. */
	private function updatesPackages(): bool
	{
		return array_any($this->plan->findSteps(), fn(Step $step) => $step->kind === StepKind::Composer && $step->update);
	}


	private function formatComposerStep(int $number): string
	{
		$step = $this->plan->steps[$number - 1];
		$require = implode(' ', array_map(
			fn(string $package, string $constraint) => "\"$package:$constraint\"",
			array_keys($step->require),
			$step->require,
		));
		return "$step->title: run `composer require $require " . ($step->update ? '-W --prefer-lowest --minimal-changes' : '--no-update')
			. '`, then `dresscode upgrade --continue`';
	}
}
