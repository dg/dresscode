<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\UnaryOpNode;


/**
 * A `(bool)` cast instead of the double negation `!!`; a comment between the operators keeps them.
 */
#[RuleInfo(Stage::Structure)]
final class NoDoubleNegationsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('expressions.doubleNegation', Domain::state('forbidden'), '`!!$a` is `(bool) $a`')];
	}


	public function getVisitedNodes(): array
	{
		return [UnaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof UnaryOpNode
			|| !$node->operator->is('!')
			|| !($inner = $node->expression) instanceof UnaryOpNode
			|| !$inner->operator->is('!')
			|| $node->operator->hasCommentUpTo($inner->expression->getFirstToken())
			|| !$context->report($node, 'The double negation `!!` must be written as a `(bool)` cast.')
		) {
			return;
		}

		// `!` binds looser than a cast, so an operand like `$a instanceof B` goes into parentheses
		$node->replaceWith((new Builder)->cast('bool', $inner->expression));
	}
}
