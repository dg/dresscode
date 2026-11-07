<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * A package of the lock refusing a target, with the constraint it refuses it by.
 * @internal
 */
final readonly class Blocker
{
	public function __construct(
		public string $package,
		/** the version the lock holds */
		public string $version,
		/** the constraint of the target it requires, `8.1 - 8.5`, or the conflict it declares */
		public string $constraint,
	) {
	}
}
