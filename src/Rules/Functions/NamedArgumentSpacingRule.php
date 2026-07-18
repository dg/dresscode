<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Nodes\ArgumentNode;


/**
 * A named argument as `name: $value`: no whitespace before the colon, a single space after it.
 */
#[RuleInfo(
	'dresscode/named-argument-spacing',
	Stage::Formatting,
	description: 'Normalizes whitespace around the colon of a named argument',
)]
final class NamedArgumentSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		return [
			ArgumentNode::class => [
				'name' => [null, Claim::none()],
				'colon' => [null, Claim::single()],
			],
		];
	}
}
