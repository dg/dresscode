<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, GapRule, Line, RuleInfo, Space, Stage};


/**
 * No whitespace between `...` and its operand, which stays on its line, in arguments, parameters and arrays alike.
 */
#[RuleInfo(
	'dresscode/spread-operator-spacing',
	Stage::Formatting,
	description: 'Removes whitespace between ... and its operand',
)]
final class SpreadOperatorSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		return ['*' => ['ellipsis' => [null, new Claim(Space::None, line: Line::Same)]]];
	}
}
