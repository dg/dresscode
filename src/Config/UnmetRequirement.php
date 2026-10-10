<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;


/**
 * A package a rule needs that the project does not meet.
 * @internal
 */
final readonly class UnmetRequirement
{
	public function __construct(
		public string $package,
		/** the Composer constraint required */
		public string $constraint,
		/** the versions the project is written for, null where it does not have the package */
		public ?string $current,
	) {
	}


	/** What the rule needs and what the project has instead, as a message says it. */
	public function format(): string
	{
		$needs = '`' . ($this->constraint === '*' ? $this->package : "$this->package $this->constraint") . '`';
		return $this->current === null
			? "$needs and the project does not have it"
			: "$needs and the project is written for " . Versions::formatVersion($this->current);
	}
}
