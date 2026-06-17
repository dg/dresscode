<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\Violation;


/**
 * Outcome of the passes over one file.
 * @internal
 */
final readonly class PassResult
{
	public function __construct(
		/** @var list<Violation> */
		public array $violations,
		/** @var list<string> contract violations of rules the runner tolerated */
		public array $warnings,
		public int $passes,
		public bool $mutated,
		/** @var list<string> rules that mutated the file */
		public array $mutatedRules = [],
	) {
	}
}
