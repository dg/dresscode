<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * A parameter of a function: its name and how it takes the argument.
 */
final readonly class Parameter
{
	public function __construct(
		public string $name,
		public bool $variadic = false,
		public bool $byReference = false,
	) {
	}
}
