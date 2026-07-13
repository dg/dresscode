<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\TernaryNode;


/**
 * The elvis form `$a ?: $b` for a one-line ternary that repeats its condition; only for expressions
 * free of side effects, which a second evaluation cannot change.
 */
#[RuleInfo(
	'dresscode/short-ternary-operator',
	Stage::Structure,
	description: 'Uses ?: where the ternary repeats its condition',
	group: Group::Modernization,
)]
final class ShortTernaryOperatorRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof TernaryNode
			|| $node->then === null
			|| !$node->condition->isRepeatableRead()
			|| !$node->condition->matches($node->then)
			|| $node->question->getLine() !== $node->colon->getLine()
			|| $node->question->hasCommentUpTo($node->colon)
			|| !$context->report($node, "A ternary repeating its condition must be written '?:'")
		) {
			return;
		}

		$node->then = null;
		$node->question->setTrailingTrivia([]);
	}
}
