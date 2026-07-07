<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;


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
