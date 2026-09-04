<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * What a deprecation says: its text, and the replacement it names when it names one in a shape a tool
 * can read, `use Order::StatusPaid`, `use recalculate()`, `use $items`.
 */
final readonly class Deprecation
{
	public function __construct(
		public string $description,
		/** the class of the replacement as the description writes it; null when it writes none, the replacement then being a member of the same class */
		public ?string $replacementClass = null,
		/** name of the replacement member, without the parentheses of a method */
		public ?string $replacementName = null,
		/** the replacement is written as a call */
		public bool $replacementIsCall = false,
	) {
	}


	public static function fromDescription(string $description): self
	{
		return preg_match('~^use\s+(?:([\w\\\\]+)::)?(\$?\w+)(\(\))?(?:\s+instead)?\.?$~iD', trim($description), $m)
			? new self($description, $m[1] === '' ? null : ltrim($m[1], '\\'), $m[2], isset($m[3]))
			: new self($description);
	}
}
