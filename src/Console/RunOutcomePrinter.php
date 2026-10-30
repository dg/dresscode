<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Upgrade\{Plan, RunOutcome, RunStop, StepKind};
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
		$label = fn(string $label) => $console->color('gray', str_pad($label, 11));
		$outcome = $this->outcome;
		$out = '';
		foreach ($this->plan->findSteps() as $number => $step) {
			if (
				$step->kind === StepKind::Manual
				&& ($outcome->step === null || $number < $outcome->step)
				&& ($this->only ?? $number) === $number
			) {
				$out .= $label("Step $number") . "for you, $step->title" . ($step->suggest === null ? '' : ": `$step->suggest`") . "\n";
			}
		}

		foreach ($outcome->fixed as $number => $topics) {
			$out .= $label("Step $number") . implode(', ', array_map(
				fn(string $topic, array $files) => "$topic "
					. ($files === [] ? 'left nothing to fix' : Markup::formatCount(count($files), 'file')),
				array_keys($topics),
				$topics,
			)) . "\n";
		}

		$tasks = array_merge(...array_values($outcome->tasks));
		if ($tasks !== []) {
			$out .= $label('For you') . Markup::formatCount(count($tasks), 'place') . " the fixes left\n";
			foreach ($tasks as $task) {
				$violation = $task->violation;
				$out .= "  $task->path:$violation->line  $violation->message  " . $console->color('gray', "[$violation->decision]") . "\n";
			}
		}

		return $out . match ($outcome->stop) {
			RunStop::Tests => $label('Failed') . "step $outcome->step went back, as " . ($outcome->failures === []
				? 'the tests wrote no results after it'
				: 'these tests now fail: ' . implode(', ', $outcome->failures)),
			RunStop::Composer => $label('Waiting') . "step $outcome->step, {$this->formatComposerStep((int) $outcome->step)}",
			RunStop::Tasks => $label('Waiting')
				. "step $outcome->step leaves the places above: fix each or confirm it with "
				. '`// dresscode:ignore <decision> -- <reason>`, then run `dresscode upgrade --continue`',
			RunStop::Done => $console->color('white/green', $this->only === null
				? 'UPGRADED  every step of the plan is done'
				: "DONE      step $this->only"),
		} . "\n";
	}


	/**
	 * The run as the data the format `json` writes beside the plan.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$outcome = $this->outcome;
		$fixed = $tasks = [];
		foreach ($outcome->fixed as $step => $topics) {
			foreach ($topics as $topic => $files) {
				$fixed[] = ['step' => $step, 'topic' => $topic, 'files' => $files];
			}
		}

		foreach ($outcome->tasks as $topic => $topicTasks) {
			foreach ($topicTasks as $task) {
				$tasks[] = [
					'topic' => $topic,
					'file' => $task->path,
					'line' => $task->violation->line,
					'decision' => $task->violation->decision,
					'message' => $task->violation->message,
				];
			}
		}

		return [
			'stop' => lcfirst($outcome->stop->name),
			'step' => $outcome->step,
			'fixed' => $fixed,
			'tasks' => $tasks,
			'failures' => $outcome->failures,
		];
	}


	private function formatComposerStep(int $number): string
	{
		$step = $this->plan->steps[$number - 1];
		$require = implode(' ', array_map(
			fn(string $package, string $constraint) => "\"$package:$constraint\"",
			array_keys($step->require),
			$step->require,
		));
		return "$step->title: run `composer require $require -W" . ($step->update ? '' : ' --no-update')
			. '`, then `dresscode upgrade --continue`';
	}
}
