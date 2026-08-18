<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Risk;


/**
 * An entry of the upgrading data of PHP as the rule works with it: what it matches, what it does, the version that retired
 * the call and the version from which what it does is right.
 * @internal
 */
final readonly class UpgradingEntry
{
	public function __construct(
		public FunctionPattern|MemberPattern $pattern,
		public UpgradingOperation $operation,
		/** the version of the section, which retired the call */
		public string $retiredIn,
		/** the oldest target the operation is right for */
		public string $appliesFrom,
		/** the code written instead, for a replacement */
		public ?string $write = null,
		public ?Risk $risk = null,
		public ?string $because = null,
	) {
	}


	/**
	 * The name the entry is looked up by: the function, or `class::method` in lower case.
	 * @return lowercase-string
	 */
	public function getLookupName(): string
	{
		return $this->pattern instanceof FunctionPattern
			? $this->pattern->name
			: strtolower($this->pattern->class . '::' . $this->pattern->name);
	}
}
