<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, GapRule, Line, RuleInfo, Space, Stage};
use PhpSyntax\Nodes\Expression\{PostfixOpNode, PrefixOpNode, UnaryOpNode, VariableNode};


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
