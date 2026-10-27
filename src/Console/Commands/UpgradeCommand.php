<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Console\{CommandContext, RunExecutor};
use Nette\CommandLine\{Attributes as Cli, ParseResult};


/**
 * The command `upgrade today`: the code moved to the lowest versions of PHP and of the libraries the project allows.
 * @internal
 */
final readonly class UpgradeCommand
{
	private RunExecutor $executor;


	public function __construct(
		private CommandContext $context,
	) {
		$this->executor = new RunExecutor($context);
	}


	#[Cli\Command('Rewrite the old API for the versions `composer.json` requires now, keeping the style of the project')]
	public function today(ParseResult $args, TodayOptions $options, ConfigOptions $configuration): int
	{
		return $this->executor->run(
			$args,
			$configuration,
			$options->toRunOptions(),
			new CheckOptions(fixRisky: $options->fixRisky, only: $options->only),
			fix: true,
			askRisky: (bool) $options->askRisky,
			upgrade: new TodayUpgrade($this->context, (bool) $options->modernize, $options->only),
		);
	}
}
