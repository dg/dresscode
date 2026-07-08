<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;


/**
 * No whitespace around `->` and `?->` on a line, nor inside the braces of a dynamic name: `$a->{$b}`. The name
 * stays on the line of the operator; a line break before the operator is dresscode/multi-line-chain's.
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
		$hug = new Claim(Space::None, line: Line::Same);
		$slots = [
			'operator' => [Claim::none(), $hug],
			'openBrace' => [null, $hug],
			'closeBrace' => [$hug, null],
		];
		return [MethodCallNode::class => $slots, PropertyFetchNode::class => $slots];
	}
}
