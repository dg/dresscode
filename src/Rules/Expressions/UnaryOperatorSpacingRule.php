<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Nodes\Expression\PostfixOpNode;
use PhpSyntax\Nodes\Expression\PrefixOpNode;
use PhpSyntax\Nodes\Expression\UnaryOpNode;
use PhpSyntax\Nodes\Expression\VariableNode;


/**
 * No whitespace between a unary operator and its operand: `!$a`, `-$b`, `$i++`, nor inside a variable
 * variable, `$$a` and `${'a'}`.
 */
#[RuleInfo(
	'dresscode/unary-operator-spacing',
	Stage::Formatting,
	description: 'Removes whitespace between a unary operator and its operand',
)]
final class UnaryOperatorSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		return [
			UnaryOpNode::class => ['operator' => [null, Claim::none()]],
			PrefixOpNode::class => ['operator' => [null, Claim::none()]],
			PostfixOpNode::class => ['operator' => [Claim::none(), null]],
			VariableNode::class => [
				'dollar' => [null, Claim::none()],
				'openBrace' => [null, Claim::none()],
				'closeBrace' => [Claim::none(), null],
			],
		];
	}
}
