<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Interop\{FormatterCandidate, FormatterConfig};


/**
 * The configuration of the formatter a command of the move from it works with, and how it came to be the one.
 * @internal
 */
final readonly class FormatterChoice
{
	public function __construct(
		/** null where the project has none */
		public ?FormatterConfig $config,
		/** @var list<FormatterCandidate>  the other configurations the project has, which nobody chose between */
		public array $unchosen = [],
		/** named on the command line, which is consent to read it even where reading runs it */
		public bool $named = false,
	) {
	}
}
