<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Rule;


/**
 * One rule of a resolved configuration: its class, who decided what makes it run or not, and when it does not run, why.
 * @internal
 */
final readonly class ResolvedRule
{
	public function __construct(
		/** @var class-string<Rule> */
		public string $class,
		/** the layer that said the value of a decision that runs it, or of the last one that turned it off; null for neither */
		public ?Layer $source = null,
		/** why the rule does not run, as a sentence; null when it does */
		public ?string $inactiveMessage = null,
		/** @var ?\Closure(): Rule  a rule the configuration builds itself */
		public ?\Closure $factory = null,
		/** the project accepts the fixes of the rule that may change what the code does */
		public bool $fixRisky = false,
		/** the violations of the rule only warn */
		public bool $warnOnly = false,
		/** why the rule does not run; null when it does */
		public ?InactiveReason $inactiveReason = null,
	) {
	}


	public function isActive(): bool
	{
		return $this->inactiveReason === null;
	}
}
