<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Rule;


/**
 * What a name of `only`, `fixRisky` or `warnOnly` stands for.
 * @internal
 */
final readonly class ExpandedName
{
	public function __construct(
		/** the decision or the section, the preset or the class of the rule the name stands for */
		public string $name,
		public bool $preset,
		/** @var ?class-string<Rule>  the rule the name names outright */
		public ?string $rule,
		/** @var list<class-string<Rule>>  the rules owning the decisions it stands for */
		public array $rules,
		/** @var list<string>  the decisions it stands for */
		public array $paths,
	) {
	}


	/** The name as a message says it, with the code it names in backticks. */
	public function format(): string
	{
		return match (true) {
			$this->rule !== null => "rule `$this->name`",
			$this->preset => "preset `$this->name`",
			default => "decision `$this->name`",
		};
	}
}
