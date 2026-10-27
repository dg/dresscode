<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Console\{CommandContext, RunExecutor};
use Nette\CommandLine\{Attributes as Cli, ParseResult};


/**
 * The commands `check`, `fix` and `baseline`: the run over the files of the project, or over the code of stdin.
 * @internal
 */
final readonly class CheckCommand
{
	private RunExecutor $executor;


	public function __construct(CommandContext $context)
	{
		$this->executor = new RunExecutor($context);
	}


	#[Cli\Command('Report the violations')]
	public function check(ParseResult $args, RunOptions $run, CheckOptions $check, ConfigOptions $configuration): int
	{
		return $this->executor->run($args, $configuration, $run, $check);
	}


	#[Cli\Command('Fix what the rules can and report the rest')]
	public function fix(
		ParseResult $args,
		RunOptions $run,
		CheckOptions $check,
		#[Cli\Flag('Fix as usual, then ask about every risky fix left, one at a time with its diff, and make those accepted; needs an interactive terminal')]
		?bool $askRisky,
		ConfigOptions $configuration,
	): int
	{
		return $this->executor->run($args, $configuration, $run, $check, fix: true, askRisky: (bool) $askRisky);
	}


	#[Cli\Command('Write the violations a fix leaves into the baseline file of the configuration, once a fix changes nothing')]
	public function baseline(ParseResult $args, RunOptions $run, ConfigOptions $configuration): int
	{
		return $this->executor->run($args, $configuration, $run, generate: true);
	}
}
