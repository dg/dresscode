<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * The recipe of an upgrade: the targets, the steps in their order, the versions of the tool and of the upgrading data
 * it was made with, and the commands that test the project.
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
		public string $dresscode,
		/** @var array<string, string>  package of upgrading data => its version, `php` for the data DressCode ships */
		public array $data = [],
		/** the command testing the whole project, `{junit}` standing for the file it writes its results to */
		public ?string $testCommand = null,
		/** the command testing what is quick to test, after every step */
		public ?string $quickTestCommand = null,
	) {
	}


	/**
	 * Whether the plan was made with this version of DressCode and these versions of the upgrading data, without which
	 * a step may fix more or less than it did when the plan was made.
	 * @param  array<string, string>  $data  package of upgrading data => its version
	 */
	public function isMadeWith(string $dresscode, array $data): bool
	{
		return $this->dresscode === $dresscode && $this->data === $data;
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
