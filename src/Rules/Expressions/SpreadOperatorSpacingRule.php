<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, GapRule, Line, RuleInfo, Space, Stage};
use DressCode\Domains\Shapes;


/**
 * No whitespace between `...` and its operand, which stays on its line, in arguments, parameters and arrays alike.
 */
#[RuleInfo(Stage::Formatting)]
final class SpreadOperatorSpacingRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.spread', new Shapes(['compact' => ['...$x', 'none between']]), 'The whitespace between `...` and its operand')];
	}


	public function getClaims(): array
	{
		return ['*' => ['ellipsis' => [null, new Claim(Space::None, line: Line::Same)]]];
	}
}
