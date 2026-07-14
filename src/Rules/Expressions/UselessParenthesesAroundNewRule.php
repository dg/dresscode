<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{NewNode, ParenthesizedNode};


/**
 * Since PHP 8.4 a member of a new object is accessed without parentheses around the instantiation:
 * `new Foo()->bar()`, not `(new Foo())->bar()`. The argument parentheses stay: they are what makes it work.
 */
#[RuleInfo(
	'dresscode/useless-parentheses-around-new',
	Stage::Structure,
	description: 'Removes the parentheses around new when a member of the new object is accessed',
	group: Group::Cleanup,
	requires: ['php' => '>=8.4'],
)]
final class UselessParenthesesAroundNewRule extends NodeRule
{
	public function getVisitedTypes(): array
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
			|| $node->openParen->hasComment()
			|| $node->closeParen->hasComment()
			|| !$context->report($node->openParen, 'Useless parentheses around new, a member is accessible without them')
		) {
			return;
		}

		$copy = $new->withoutEdgeTrivia();
		$node->replaceWith($copy);
	}
}
