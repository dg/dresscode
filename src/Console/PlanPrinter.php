<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Upgrade\{DependencyStep, FixStep, ManualStep, Plan, PlanFile, Step, Target};
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
		/** whether the plan is of a library or of an application, and why, where the plan was made just now */
		private ?string $mode = null,
	) {
	}


	public function print(Console $console): string
	{
		$out = ($this->mode === null ? '' : Markup::formatLabel($console, 'Mode') . "$this->mode\n")
			. Markup::formatLabel($console, 'Plan')
			. implode(', ', array_map(fn(Target $target) => $target->format(), $this->plan->targets))
			. ($this->plan->paths === [] ? '' : ' over ' . implode(', ', $this->plan->paths))
			. "\n";
		$width = max([0, ...array_map(fn(Step $step) => strlen($step->title), $this->plan->steps)]);
		foreach ($this->plan->steps as $index => $step) {
			$out .= rtrim(sprintf(
				' %2d  %s  %s  %s',
				$index + 1,
				$console->color(...match (true) {
					$step->skip => ['gray', 'skipped '],
					$step instanceof ManualStep => ['yellow', 'manual  '],
					$step instanceof DependencyStep => ['white', 'composer'],
					default => ['green', 'fix     '],
				}),
				str_pad($step->title, $width),
				match (true) {
					$step instanceof FixStep => implode(', ', $step->topics),
					$step instanceof ManualStep => $step->formatCommand(),
					default => '',
				},
			)) . "\n";
		}

		$test = $this->plan->testCommand;
		$quick = $this->plan->quickTestCommand;
		return $out . Markup::formatLabel($console, 'Tests') . match (true) {
			$test === null && $quick === null => 'not run, no `--test-command` given',
			$test === null || $quick === null => Violation::formatCode($test ?? $quick) . ' after every step',
			default => Violation::formatCode($quick)
				. ' after every step, '
				. Violation::formatCode($test)
				. ' after a raise and the last one',
		} . "\n";
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
				...PlanFile::encodeStep($step),
				'commands' => match (true) {
					$step instanceof DependencyStep => $step->formatCommands(),
					$step instanceof ManualStep => [$step->formatCommand()],
					default => [],
				},
				'skip' => $step->skip,
			], $this->plan->steps),
			'toolVersion' => $this->plan->toolVersion,
			'dataVersions' => (object) $this->plan->dataVersions,
			'testCommand' => $this->plan->testCommand,
			'quickTestCommand' => $this->plan->quickTestCommand,
			'paths' => $this->plan->paths,
			'unverified' => $this->plan->unestimated,
			'missingData' => (object) $this->plan->missingData,
		];
	}
}
