<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{NewNode, ParenthesizedNode};


/**
 * Since PHP 8.4 a member of a new object is accessed without parentheses around the instantiation:
 * `new Foo()->bar()`, not `(new Foo())->bar()`. The argument parentheses stay: they are what makes it work.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.4'])]
final class UselessParenthesesAroundNewRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'classes.instantiationWrapping',
			Domain::state('forbidden'),
			'The parentheses around an instantiation a member is accessed on, `new Foo()->bar()` for `(new Foo())->bar()`; one without the parentheses of its arguments stays as it is',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [ParenthesizedNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ParenthesizedNode
			|| !($new = $node->expression) instanceof NewNode
			|| $new->arguments === null
			|| !$node->isDereferenced()
			|| $node->openParen->hasCommentUpTo($new->getFirstToken())
			|| $new->getLastToken()->hasCommentUpTo($node->closeParen)
			|| !$context->report($node->openParen, 'Useless parentheses around `new`, because PHP 8.4 reaches a member without them.')
		) {
			return;
		}

		$copy = $new->withoutEdgeTrivia();
		$node->replaceWith($copy);
	}
}
