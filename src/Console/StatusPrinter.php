<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Upgrade\{FixStep, Plan, PlanStatus, Step, StepStatus, Target};
use DressCode\Violation;
use Nette\CommandLine\Console;
use function strlen;


/**
 * Writes where the saved plan stands: the steps done and due, what a run keeps to go back, and the command to run next.
 * @internal
 */
final readonly class StatusPrinter
{
	/** the version of the document the format `json` writes */
	public const Version = 1;


	public function __construct(
		private Plan $plan,
		private PlanStatus $status,
	) {
	}


	public function print(Console $console): string
	{
		$status = $this->status;
		$out = Markup::formatLine($console, 'Plan', implode(', ', array_map(fn(Target $target) => $target->format(), $this->plan->targets)))
			. "\n";
		$width = max([0, ...array_map(fn(Step $step) => strlen($step->title), $this->plan->steps)]);
		foreach ($this->plan->steps as $index => $step) {
			$out .= sprintf(' %2d  %s  %s', $index + 1, str_pad($step->title, $width), match ($status->steps[$index + 1]) {
				StepStatus::Done => $console->color('green', 'done'),
				StepStatus::Due => $console->color('yellow', 'due'),
				StepStatus::Skipped => $console->color('gray', 'skipped'),
				StepStatus::Unknown => Markup::formatAside($console, $step instanceof FixStep
					? 'what is left `upgrade run` fixes and `upgrade tasks` lists'
					: '`upgrade run` tells'),
			}) . "\n";
		}

		foreach ($status->interrupted as $step => $files) {
			$out .= Markup::formatLine($console, 'Stopped', "step $step did not finish and may have rewritten " . implode(', ', $files))
				. "\n";
		}

		if ($status->raisedFiles !== []) {
			$out .= Markup::formatLine(
				$console,
				'Raised',
				"step $status->raisedStep changed " . implode(', ', $status->raisedFiles),
				', which `dresscode upgrade abort` puts back',
			) . "\n";
		}

		if ($status->awaitingInstall) {
			$out .= Markup::formatLine(
				$console,
				'Install',
				'the files of Composer are put back, but not the packages yet',
				', which `dresscode upgrade abort` installs',
			) . "\n";
		}

		if ($status->uncommittedStep !== null) {
			$out .= Markup::formatLine(
				$console,
				'Commit',
				"step $status->uncommittedStep is not committed yet",
				', which the next `upgrade run --commit` does',
			) . "\n";
		}

		foreach ($status->baselines as $command => $time) {
			$out .= ($time === null
				? Markup::formatLine(
					$console,
					'Tests',
					Violation::formatCode($command) . ' has no run before the first step yet',
					', which the next `upgrade run` takes',
				)
				: Markup::formatLine(
					$console,
					'Tests',
					Violation::formatCode($command) . ' is compared with its run of ' . date('Y-m-d H:i', $time),
				))
				. "\n";
		}

		return $out . Markup::formatLine($console, 'Next', $status->interrupted === []
			? '`dresscode upgrade run` carries on from where the code stands, and ends at once where nothing is left'
			: '`dresscode upgrade abort --dry-run` shows what `upgrade abort` puts back, or `dresscode upgrade run --discard` goes on from the code as it is')
			. "\n";
	}


	/**
	 * Where the plan stands as the data the format `json` writes.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$status = $this->status;
		return [
			'version' => self::Version,
			'steps' => array_map(fn(StepStatus $step) => lcfirst($step->name), $status->steps),
			'interrupted' => (object) $status->interrupted,
			'raisedStep' => $status->raisedStep,
			'raisedFiles' => $status->raisedFiles,
			'awaitingInstall' => $status->awaitingInstall,
			'uncommittedStep' => $status->uncommittedStep,
			'baselines' => (object) array_map(fn(?int $time) => $time === null ? null : date(DATE_ATOM, $time), $status->baselines),
		];
	}
}
