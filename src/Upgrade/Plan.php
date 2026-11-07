<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * The recipe of an upgrade: the targets, the steps in their order, the versions of the tool and of the upgrading data
 * it was made with, the commands that test the project, the paths its fixes are narrowed to, and what the estimate it
 * was made from could not see.
 * @internal
 */
final readonly class Plan
{
	public function __construct(
		/** @var list<Target> */
		public array $targets,
		/** @var list<Step> */
		public array $steps,
		/** the version of DressCode that made the plan */
		public string $toolVersion,
		/** @var array<string, string>  package of upgrading data => its version, `php` for the data DressCode ships */
		public array $dataVersions = [],
		/** the command testing the whole project, `{junit}` standing for the file it writes its results to */
		public ?string $testCommand = null,
		/** the command testing what is quick to test, after every step */
		public ?string $quickTestCommand = null,
		/** @var list<string>  the paths within the configured ones the fixes are narrowed to, relative to the root; none for all */
		public array $paths = [],
		/** @var list<string>  the files the estimate could not process, whose places no step counts on, relative to the root */
		public array $unestimated = [],
		/** @var array<string, list<string>>  a package of upgrading data the estimate lacked => the libraries it is about */
		public array $missingData = [],
	) {
	}


	/** Whether the plan was made with what is given, the version of DressCode and those of the upgrading data. */
	public function isMadeWith(Provenance $provenance): bool
	{
		return $this->toolVersion === $provenance->tool && $this->dataVersions === $provenance->data;
	}


	/**
	 * The commands of the tests the plan gives, the whole suite first.
	 * @return list<string>
	 */
	public function listTestCommands(): array
	{
		return array_values(array_filter([$this->testCommand, $this->quickTestCommand]));
	}


	/** The command testing after a step: the whole suite where `$whole`, else the quicker one, each standing in for the other. */
	public function selectTestCommand(bool $whole): ?string
	{
		return $whole ? $this->testCommand ?? $this->quickTestCommand : $this->quickTestCommand ?? $this->testCommand;
	}


	/**
	 * The steps the run makes, those the user left out aside, by their number from 1.
	 * @return array<int, Step>
	 */
	public function findSteps(): array
	{
		$steps = [];
		foreach ($this->steps as $index => $step) {
			if (!$step->skip) {
				$steps[$index + 1] = $step;
			}
		}

		return $steps;
	}
}
