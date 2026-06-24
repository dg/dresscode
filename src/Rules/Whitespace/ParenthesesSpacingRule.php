<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, GapRule, RuleInfo, Stage};
use DressCode\Domains\Shapes;


/**
 * No whitespace just inside parentheses on a line: `foo($a)`, `if ($a)`, not `foo( $a )`.
 */
#[RuleInfo(Stage::Formatting)]
final class ParenthesesSpacingRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.parentheses', new Shapes(['compact' => ['($a)', 'none inside']]), 'The whitespace inside parentheses')];
	}


	public function getClaims(): array
	{
		return [
			'*' => [
				'openParen' => [null, Claim::noSpace()],
				'closeParen' => [Claim::noSpace(), null],
			],
		];
	}
}
