<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, GapRule, RuleInfo, Stage};


/**
 * No whitespace just inside parentheses on a line: `foo($a)`, `if ($a)`, not `foo( $a )`.
 */
#[RuleInfo(
	'dresscode/parentheses-spacing',
	Stage::Formatting,
	description: 'Removes whitespace inside parentheses',
)]
final class ParenthesesSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		return [
			'*' => [
				'openParen' => [null, Claim::none()],
				'closeParen' => [Claim::none(), null],
			],
		];
	}
}
