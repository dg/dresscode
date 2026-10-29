<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Violation;


/**
 * What a fix leaves for a human: a place it has no fix for, or a risky one it refused. It is done once the code no
 * longer has it, or a `dresscode:ignore` or the baseline confirms it.
 * @internal
 */
final readonly class Task
{
	public function __construct(
		/** the file, relative to the root */
		public string $path,
		public Violation $violation,
	) {
	}
}
