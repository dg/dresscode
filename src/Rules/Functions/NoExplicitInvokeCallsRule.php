<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\IdentifierNode;


/**
 * An invokable object is called directly: `$handler($a)`, not `$handler->__invoke($a)`; an object held in
 * a property gets parentheses, `($this->handler)($a)`, so that PHP does not read a method call. A call in a nullsafe
 * chain stays: calling the object is no link of the chain, so a null would be called instead of skipped.
 */
#[RuleInfo(Stage::Structure)]
final class NoExplicitInvokeCallsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('cleanup.__invoke', Domain::state('forbidden'), '`$f->__invoke($x)` is `$f($x)`')];
	}


	public function getVisitedNodes(): array
	{
		return [MethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodCallNode
			|| !$node->operator->is('->')
			|| !$node->name instanceof IdentifierNode
			|| !$node->name->equals('__invoke')
			|| $node->object->isInNullsafeChain()
			|| $node->object->getLastToken()->hasCommentUpTo($node->arguments->openParen)
			|| !$context->report($node->name, 'The invokable object must be called directly, not through `__invoke()`.')
		) {
			return;
		}

		$node->replaceWith((new Builder)->call($node->object, $node->arguments));
	}
}
