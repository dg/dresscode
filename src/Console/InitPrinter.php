<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Measuring\Proposal;
use Nette\CommandLine\Console;
use function sprintf;


/**
 * Writes the measurements of a proposal out: the sample, the standard, each decision taken from the code, and what
 * a dry run of the standard, or of every standard where none was given, would change.
 * @internal
 */
final readonly class InitPrinter
{
	public function __construct(
		private Proposal $proposal,
	) {
	}


	/**
	 * The sample and what was measured in it, as text for the console with the ANSI colors of `$console` where it
	 * draws any; the dry runs take a while to follow.
	 */
	public function printMeasurements(Console $console): string
	{
		$proposal = $this->proposal;
		$left = array_filter([
			$proposal->sample->oversized ? $proposal->sample->oversized . ' too large' : null,
			$proposal->sample->generated ? $proposal->sample->generated . ' generated' : null,
		]);
		$out = $console->color('gray', 'Sample     ') . sprintf(
			"%d of %d files in %s%s\n",
			$proposal->countSampled(),
			$proposal->total,
			implode(', ', $proposal->paths),
			$left ? ', ' . implode(' and ', $left) . ' left out' : '',
		);
		$out .= $console->color('gray', 'Standard   ') . implode(', ', $proposal->presets) . match (true) {
			$proposal->presetsGiven => ", as given\n",
			$proposal->formatter !== null
				=> ", the style of {$proposal->formatter}, which the project formats with; `--use` writes another\n",
			default => ", not chosen by measure; the dry runs below count what each would change, not which is nearest, and `--use` writes another\n",
		};
		$out .= $console->color('gray', 'Indent     ') . $proposal->indent->describe() . "\n";
		$out .= $console->color('gray', 'Quotes     ') . $proposal->quotes->describe() . "\n";
		$out .= $console->color('gray', 'Conditions ') . $proposal->conditions->describe() . "\n";
		return $out . $console->color('gray', 'Namespaces ') . $proposal->describeNamespaces() . "\n";
	}


	/** What a dry run of the standard given, or of every standard over a part of the sample, would change. */
	public function printDryRuns(Console $console): string
	{
		$proposal = $this->proposal;
		if ($proposal->presetsGiven) {
			[$changed, $failed] = $proposal->countChanged();
			return $console->color('gray', 'Dry run    ') . sprintf(
				"%d of %d sampled files would change%s\n",
				$changed,
				$proposal->countSampled(),
				$failed ? ", $failed failing" : '',
			);
		}

		$out = '';
		$first = true;
		foreach ($proposal->countChangedByStandard() as $standard => [$changed, $failed]) {
			$out .= $console->color('gray', $first ? 'Dry run    ' : '           ') . sprintf(
				"%-8s %4d of %d%s%s%s\n",
				$standard,
				$changed,
				$proposal->countPriced(),
				$first ? ' sampled files would change' : '',
				in_array($standard, $proposal->presets, true) ? ', the one written' : '',
				$failed ? ", $failed failing" : '',
			);
			$first = false;
		}

		return $out;
	}
}
