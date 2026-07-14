<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;


/**
 * No whitespace around `->` and `?->` on a line, nor inside the braces of a dynamic name: `$a->{$b}`.
 */
#[RuleInfo(
	'dresscode/object-operator-spacing',
	Stage::Formatting,
	description: 'Removes whitespace around the object operator',
)]
final class ObjectOperatorSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		$slots = [
			'operator' => [Claim::none(), Claim::none()],
			'openBrace' => [null, Claim::none()],
			'closeBrace' => [Claim::none(), null],
		];
		return [MethodCallNode::class => $slots, PropertyFetchNode::class => $slots];
	}
}
