<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, GapRule, Line, RuleInfo, Space, Stage};
use DressCode\Domains\Shapes;


/**
 * No whitespace between `&` and its operand, which stays on its line: `&$a`, `function &f()`, `$a = &$b`.
 */
#[RuleInfo(Stage::Formatting)]
final class ReferenceSpacingRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.reference', new Shapes(['compact' => ['&$x', 'none between']]), 'The whitespace between `&` and its operand')];
	}


	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		return [
			'*' => ['ampersand' => [null, $hug]],
		];
	}
}
