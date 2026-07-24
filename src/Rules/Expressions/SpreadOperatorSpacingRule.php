<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;


/**
 * No whitespace between `...` and its operand, in arguments, parameters and arrays alike.
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
		return ['*' => ['ellipsis' => [null, Claim::none()]]];
	}
}
