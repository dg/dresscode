<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use Nette\CommandLine\Attributes as Cli;


/**
 * The inputs of carrying out the plan of an upgrade, `upgrade run`.
 * @internal
 */
final readonly class PlanRunOptions
{
	public function __construct(
		#[Cli\Option('Run that step of the plan alone', valueName: 'n', normalizer: [RunOptions::class, 'parsePositiveInt'])]
		public ?int $step = null,
		#[Cli\Flag('Go on from the code as it is after a run interrupted in a step, which may have rewritten some files')]
		public ?bool $discard = null,
	) {
	}
}
