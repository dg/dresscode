<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\MagicConstantNode;


/**
 * Magic constants in their canonical casing: `__DIR__`, not `__dir__`.
 */
#[RuleInfo(
	'dresscode/magic-constant-casing',
	Stage::Structure,
	description: 'Writes magic constants in uppercase',
)]
final class MagicConstantCasingRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [MagicConstantNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof MagicConstantNode) {
			return;
		}

		$canonical = strtoupper($node->token->text);
		if (
			$canonical !== $node->token->text
			&& $context->report($node, "The magic constant must be written '$canonical'")
		) {
			$node->token->setText($canonical);
		}
	}
}
