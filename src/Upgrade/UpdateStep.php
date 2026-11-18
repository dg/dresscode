<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * The step updating every package to the newest version the constraints allow, which a raise left at the lowest.
 * @internal
 */
final readonly class UpdateStep implements DependencyStep
{
	public function __construct(
		public string $title,
		public bool $skip = false,
	) {
	}


	public function formatCommands(): array
	{
		return ['composer update'];
	}
}
