<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * What putting back what a run keeps to go back did, or would do.
 * @internal
 */
final readonly class Restoration
{
	public function __construct(
		/** @var list<string>  the files written back, relative to the root, those of Composer by their names */
		public array $restored,
		/** @var array<string, string>  the files left as they are => why */
		public array $left,
		/** the packages of the lock are installed after the files of Composer went back */
		public bool $installs,
		/** why `composer install` failed; null where it did not run or did not fail */
		public ?string $installFailure = null,
	) {
	}
}
