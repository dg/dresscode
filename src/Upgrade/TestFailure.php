<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * Tests that fail after a step though they did not before it, or a command of the tests that crashed.
 * @internal
 */
final readonly class TestFailure
{
	public function __construct(
		/** @var list<string>  the tests failing newly, none where the command gave no results */
		public array $newFailures,
		/** how the command crashed */
		public ?string $crash,
	) {
	}
}
