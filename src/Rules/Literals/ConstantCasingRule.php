<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\{BooleanNode, NullNode};


/**
 * Lowercase `true`, `false` and `null`; a leading backslash is part of how the literal is written and stays.
 */
#[RuleInfo(
	'dresscode/constant-casing',
	Stage::Structure,
	description: 'Lowercases true, false and null',
)]
final class ConstantCasingRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [BooleanNode::class, NullNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BooleanNode && !$node instanceof NullNode) {
			return;
		}

		$token = $node->token;
		$lower = strtolower($token->text);
		if (
			$token->text === $lower
			|| !$context->report($node, "The literal '{$token->text}' must be written '$lower'")
		) {
			return;
		}

		$token->setText($lower);
	}
}
