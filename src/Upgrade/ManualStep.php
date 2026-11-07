<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * The step a human makes, freeing a target that something of the lock refuses.
 * @internal
 */
final readonly class ManualStep implements Step
{
	public function __construct(
		public string $title,
		public Target $target,
		public bool $skip = false,
	) {
	}


	/** The command telling the human what refuses the target. */
	public function formatCommand(): string
	{
		return "composer why-not {$this->target->package} {$this->target->version}";
	}
}
