<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use Nette\CommandLine\Console;


/**
 * The line a long command keeps rewritten while a phase runs: its label and what it does.
 * @internal
 */
final readonly class StatusLine
{
	public function __construct(
		private Console $console,
	) {
	}


	/** Starts a phase: the label in the column of the labels and what it does. */
	public function show(string $label, string $text): void
	{
		$this->console->setStatus($this->console->color('gray', str_pad($label, 11)) . $text . '…');
	}
}
