<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use Nette\CommandLine\Attributes as Cli;


/**
 * The inputs of making the plan of an upgrade, `upgrade plan`.
 * @internal
 */
final readonly class PlanOptions
{
	public function __construct(
		#[Cli\Option(
			'The command testing the project after the steps, writing its JUnit report to `{junit}`, which the plan keeps',
			valueName: 'command',
		)]
		public ?string $testCommand = null,
		#[Cli\Option(
			'The quicker command testing the project after every step, `{junit}` as in `--test-command`, which then tests after a raise and the last step',
			valueName: 'command',
		)]
		public ?string $quickTestCommand = null,
		#[Cli\Flag(
			'Plan a library: widen its constraints and keep the versions it allows. `--no-library` plans an application; by default its `type` and lock decide',
			negatable: true,
		)]
		public ?bool $library = null,
	) {
	}


	/** Why the options given do not go together, as the command line writes them; null where they do. */
	public function findMisuse(): ?string
	{
		$noReport = array_find(
			[$this->testCommand, $this->quickTestCommand],
			fn(?string $command) => $command !== null && !str_contains($command, '{junit}'),
		);
		return $noReport === null
			? null
			: "A command of the tests writes its JUnit report where `{junit}` stands, and `$noReport` has none.";
	}
}
