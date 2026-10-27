<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use Nette\CommandLine\Console;
use function count;


/**
 * The line a long command keeps rewritten while a phase runs: its label, what it does, and a spinner with how far the
 * phase got, so that a long step shows it is alive.
 * @internal
 */
final class StatusLine
{
	/** a star swelling and shrinking */
	private const SpinnerFrames = ['·', '✢', '✶', '✻', '✽', '✻', '✶', '✢'];

	/** frames a second */
	private const SpinnerSpeed = 8;

	private const SpinnerColor = 'teal';

	/** the label and the text of the phase */
	private string $phase = '';

	/** what the line shows now, which is not drawn again */
	private string $shown = '';


	public function __construct(
		private readonly Console $console,
		/** false for an output read by a program, which the line would only clutter */
		private readonly bool $active = true,
	) {
	}


	/** Starts a phase: the label in the column of the labels and what it does. */
	public function show(string $label, string $text): void
	{
		if (!$this->active) {
			return;
		}

		$this->phase = Markup::formatLabel($this->console, $label) . $text . '…';
		$this->console->setStatus($this->shown = $this->phase);
	}


	/** Turns the spinner beside the phase, with the steps done and all of them where there is more than one. */
	public function advance(int $done, int $all): void
	{
		if (!$this->active) {
			return;
		}

		$line = $this->phase . '  ' . self::formatSpinner($this->console, $all > 1 ? "$done/$all" : '');
		if ($line !== $this->shown) {
			$this->console->setStatus($this->shown = $line);
		}
	}


	/** The frame of the spinner for this moment, with the progress beside it in the same color. */
	public static function formatSpinner(Console $console, string $progress = ''): string
	{
		$frame = self::SpinnerFrames[(int) (microtime(as_float: true) * self::SpinnerSpeed) % count(self::SpinnerFrames)];
		return $console->color(self::SpinnerColor, $frame . ($progress === '' ? '' : "  $progress"));
	}
}
