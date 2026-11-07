<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Console\{CommandContext, Markup};
use DressCode\Engine\Helpers;
use DressCode\Upgrade\{DependencyStep, UpdateStep};
use Nette\CommandLine\Console;
use function count;


/**
 * The agreement of the user to a step of Composer, having seen what it changes: asked in a terminal, given by `--yes`.
 * @internal
 */
final readonly class RaiseApproval
{
	public function __construct(
		private CommandContext $context,
		private Console $console,
		private bool $yes,
	) {
	}


	/** @param  list<string>  $changes  the operations of Composer, as `- Upgrading nette/utils (v3.2.10 => v4.0.11)` */
	public function approve(DependencyStep $step, array $changes): bool
	{
		$console = $this->console;
		$console->clearStatus();
		$console->writeLine(
			Markup::formatLine(
				$console,
				'Composer',
				"$step->title, " . ($changes === [] ? 'which changes no installed package' : 'which changes:'),
			),
		);
		foreach ($changes as $change) {
			$console->writeLine("  $change");
		}

		// a raise installs the lowest versions it allows, which may be older than those the lock holds today
		$downgrades = count(preg_grep('~^- Downgrading ~', $changes) ?: []);
		if ($downgrades > 0) {
			$console->writeLine(Markup::formatLine(
				$console,
				'Downgrades',
				Helpers::formatCount($downgrades, 'package') . ' the lock holds newer',
				', installed at the lowest versions the constraints allow, which the code is written for; the last step of the plan updates them',
			));
		}

		$question = $step instanceof UpdateStep ? 'Update the packages?' : 'Change `composer.json` and update the packages?';
		if (!$this->yes && $this->context->prompt->choose(Markup::highlightCode($console, $question), ['y', 'n'], 'y') !== 'y') {
			return false;
		}

		$console->setStatus(Markup::formatLabel($console, 'Upgrading') . "Composer, $step->title\u{2026}");
		return true;
	}
}
