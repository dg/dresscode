<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * What an upgrade does not see, counted so that the estimate says it.
 * @internal
 */
final readonly class BlindSpots
{
	public function __construct(
		/** the strings naming a class the maps rename or forbid, which no rename reaches */
		public int $classNameStrings = 0,
		/** the members, classes and functions reached by a name the code computes, which no map matches */
		public int $dynamicAccesses = 0,
		/** @var array<string, int>  the extension of the files no rule reads that an upgrade may change too => their count */
		public array $otherFiles = [],
		/** the files a generator wrote, as their heads say, which the estimate leaves out */
		public int $generatedFiles = 0,
	) {
	}


	public function isEmpty(): bool
	{
		return $this->classNameStrings === 0 && $this->dynamicAccesses === 0 && $this->otherFiles === [] && $this->generatedFiles === 0;
	}
}
