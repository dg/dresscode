<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;
use PhpSyntax\Nodes\Expression\AssignmentByReferenceNode;


/**
 * No whitespace between `&` and its operand, which stays on its line: `&$a`, `function &f()`, `$a = &$b`.
 */
#[RuleInfo(
	'dresscode/reference-spacing',
	Stage::Formatting,
	description: 'Removes whitespace between & and its operand',
)]
final class ReferenceSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		return [
			'*' => ['ampersand' => [null, $hug]],
			AssignmentByReferenceNode::class => ['ampersand' => [null, $hug]],
		];
	}
}
