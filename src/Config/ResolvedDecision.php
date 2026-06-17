<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Decision, Rule, Value};


/**
 * What the layers came to for one decision: the value, the values each layer said with its origin, tombstones
 * included, and why the decision does not take effect where it does not.
 * @internal
 */
final readonly class ResolvedDecision
{
	public function __construct(
		public Decision $decision,
		/** @var list<class-string<Rule>>  the rules declaring it */
		public array $rules,
		/** the merged value, the default where no layer said anything */
		public Value $value,
		/** @var list<Value>  what each layer said, from the bottom up, each with its origin */
		public array $layers = [],
		/** why it takes no effect: `keep`, `nameResolution`, or `php`, `package` where none of its rules runs; null where it does */
		public ?InactiveReason $inactive = null,
	) {
	}


	public function withInactive(InactiveReason $reason): self
	{
		return new self($this->decision, $this->rules, $this->value, $this->layers, $reason);
	}
}
