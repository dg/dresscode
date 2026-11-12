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
		$run = $options->toRunOptions();
		// a worker runs with what its parent installed, and what is installed, plugins among it, loads in a new process
		if (
			$run->worker === null
			&& new DataPackageOffer($this->context)->install($args, $configuration, (bool) $options->yes, $this->mayAsk($options->format))
		) {
			$exit = $this->context->rerun(array_values(array_filter(array_slice($_SERVER['argv'], 1), is_string(...))));
			if ($exit === null) {
				$this->context->err->writeLine('The upgrading data are installed; run the command again, which loads them.');
			}

			return $exit ?? 4;
		}

		return $this->executor->run(
			$args,
			$configuration,
			$run,
			new CheckOptions(fixRisky: $options->fixRisky, only: $options->only),
			fix: true,
			askRisky: (bool) $options->askRisky,
			upgrade: new TodayUpgrade($this->context, (bool) $options->modernize, $options->only),
		);
	}


	/** Whether the command may ask the user: in a terminal, writing text for a human rather than data for a tool. */
	private function mayAsk(?string $format): bool
	{
		return ($format ?? 'console') === 'console' && $this->context->prompt->isInteractive();
	}
}
