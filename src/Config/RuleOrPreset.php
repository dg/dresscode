<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Rule;


/**
 * What a name of a place that takes a rule or a preset stands for: one of the two.
 * @internal
 */
final readonly class RuleOrPreset
{
	public function __construct(
		/** @var ?class-string<Rule> */
		public ?string $rule = null,
		/** the name of the preset as it is registered */
		public ?string $preset = null,
	) {
	}
}
