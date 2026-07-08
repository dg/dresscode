<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;
use PhpSyntax\Nodes\Expression\PostfixOpNode;
use PhpSyntax\Nodes\Expression\PrefixOpNode;
use PhpSyntax\Nodes\Expression\UnaryOpNode;
use PhpSyntax\Nodes\Expression\VariableNode;


/**
 * No whitespace between a unary operator and its operand, which stay on one line: `!$a`, `-$b`, `$i++`, nor
 * inside a variable variable, `$$a` and `${'a'}`.
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
		$hug = new Claim(Space::None, line: Line::Same);
		return [
			UnaryOpNode::class => ['operator' => [null, $hug]],
			PrefixOpNode::class => ['operator' => [null, $hug]],
			PostfixOpNode::class => ['operator' => [$hug, null]],
			VariableNode::class => [
				'dollar' => [null, $hug],
				'openBrace' => [null, $hug],
				'closeBrace' => [$hug, null],
			],
		];
	}
}
