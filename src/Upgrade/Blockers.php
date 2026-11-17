<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * What refuses a target: the packages of the lock, or why Composer could not tell.
 * @internal
 */
final readonly class Blockers
{
	public function __construct(
		public Target $target,
		/** @var list<Blocker> */
		public array $packages = [],
		/** why Composer gave no answer, null where it gave one */
		public ?string $unknown = null,
	) {
	}
}
