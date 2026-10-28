<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Upgrade\{Plan, Step, StepKind, Target};
use DressCode\Violation;
use Nette\CommandLine\Console;
use function strlen;


/**
 * Writes the plan of an upgrade, step by step, with what each does and who does it.
 * @internal
 */
final readonly class PlanPrinter
{
	/** the version of the document the format `json` writes */
	public const Version = 1;


	public function __construct(
		private Plan $plan,
	) {
	}


	public function print(Console $console): string
	{
		$label = fn(string $label) => $console->color('gray', str_pad($label, 11));
		$out = $label('Plan') . implode(', ', array_map(fn(Target $target) => $target->format(), $this->plan->targets)) . "\n";
		$width = max([0, ...array_map(fn(Step $step) => strlen($step->title), $this->plan->steps)]);
		foreach ($this->plan->steps as $index => $step) {
			$out .= rtrim(sprintf(
				' %2d  %s  %s  %s',
				$index + 1,
				$console->color(...match (true) {
					$step->skip => ['gray', 'skipped '],
					$step->kind === StepKind::Manual => ['yellow', 'for you '],
					$step->kind === StepKind::Composer => ['white', 'composer'],
					default => ['green', 'fix     '],
				}),
				str_pad($step->title, $width),
				match ($step->kind) {
					StepKind::Fix => implode(', ', $step->only),
					StepKind::Manual => (string) $step->suggest,
					StepKind::Composer => '',
				},
			)) . "\n";
		}

		return $out . $label('Tests') . ($this->plan->testCommand === null
			? 'not run, no `--test-command` given'
			: Violation::formatCode($this->plan->testCommand)) . "\n";
	}


	/**
	 * The plan as the data the format `json` writes.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'version' => self::Version,
			'targets' => array_map(fn(Target $target) => (array) $target, $this->plan->targets),
			'steps' => array_map(fn(Step $step) => [
				'kind' => $step->kind->value,
				'title' => $step->title,
				'only' => $step->only,
				'require' => (object) $step->require,
				'update' => $step->update,
				'suggest' => $step->suggest,
				'skip' => $step->skip,
			], $this->plan->steps),
			'dresscode' => $this->plan->dresscode,
			'data' => (object) $this->plan->data,
			'testCommand' => $this->plan->testCommand,
			'quickTestCommand' => $this->plan->quickTestCommand,
		];
	}
}
