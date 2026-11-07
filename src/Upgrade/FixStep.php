<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * The step fixing topics one by one.
 * @internal
 */
final readonly class FixStep implements Step
{
	public function __construct(
		public string $title,
		/** @var list<string>  as `--only` takes them, each made apart */
		public array $topics,
		public bool $skip = false,
	) {
	}
}
