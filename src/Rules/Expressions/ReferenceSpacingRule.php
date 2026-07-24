<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Nodes\Expression\AssignmentByReferenceNode;


/**
 * No whitespace between `&` and its operand: `&$a`, `function &f()`, `$a = &$b`.
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
		return [
			'*' => ['ampersand' => [null, Claim::none()]],
			AssignmentByReferenceNode::class => ['ampersand' => [null, Claim::none()]],
		];
	}
}
