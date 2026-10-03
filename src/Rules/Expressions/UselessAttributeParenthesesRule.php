<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\AttributeNode;


/**
 * An attribute without arguments is `#[Foo]`, not `#[Foo()]`.
 */
#[RuleInfo(
	'dresscode/uselessAttributeParentheses',
	Stage::Structure,
	description: 'Removes the empty parentheses after the name of an attribute',
	group: RuleGroup::Cleanup,
)]
final class UselessAttributeParenthesesRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [AttributeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof AttributeNode) {
			return;
		}

		$args = $node->arguments;
		$name = $node->name->getLastToken();
		if (
			$args === null
			|| !$args->items->isEmpty()
			|| $args->openParen->hasLeadingComment()
			|| $args->openParen->hasCommentUpTo($args->closeParen)
			|| !$context->report($args, 'Useless empty parentheses after an attribute name')
		) {
			return;
		}

		$name->setTrailingTrivia([...$name->trailingTrivia, ...$args->closeParen->trailingTrivia]);
		$node->arguments = null;
	}
}
