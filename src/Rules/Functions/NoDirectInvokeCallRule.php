<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{FunctionCallNode, MethodCallNode};
use PhpSyntax\Nodes\IdentifierNode;


/**
 * An invokable object is called directly: `$handler($a)`, not `$handler->__invoke($a)`; an object held in
 * a property gets parentheses, `($this->handler)($a)`, so that PHP does not read a method call.
 */
#[RuleInfo(
	'dresscode/no-direct-invoke-call',
	Stage::Structure,
	description: 'Calls an invokable object directly instead of its __invoke() method',
	group: Group::Cleanup,
)]
final class NoDirectInvokeCallRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [MethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodCallNode
			|| !$node->operator->is('->')
			|| !$node->name instanceof IdentifierNode
			|| strcasecmp($node->name->token->text, '__invoke') !== 0
			|| $node->object->getLastToken()?->hasCommentUpTo($node->arguments->openParen) !== false
			|| !$context->report($node->name, 'An invokable object must be called directly, not through __invoke()')
		) {
			return;
		}

		$node->replaceWith(FunctionCallNode::of(clone $node->object, clone $node->arguments));
	}
}
