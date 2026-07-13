<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;


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
