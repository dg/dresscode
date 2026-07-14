<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Member\{MethodNode, PropertyHookNode};
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\{BlockNode, FunctionNode, ReturnNode};
use function count;


/**
 * A bare `return;` as the last statement of a function body does what the closing brace does anyway.
 */
#[RuleInfo(
	'dresscode/useless-return',
	Stage::Structure,
	description: 'Removes a bare return at the end of a function body',
	group: Group::Cleanup,
)]
final class UselessReturnRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ReturnNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$list = $node->parent;
		$block = $list?->parent;
		$owner = $block?->parent;
		if (
			!$node instanceof ReturnNode
			|| $node->expression !== null
			|| !$list instanceof NodeList
			|| !$block instanceof BlockNode
			|| (
				!$owner instanceof FunctionNode
				&& !$owner instanceof MethodNode
				&& !$owner instanceof ClosureNode
				&& !$owner instanceof PropertyHookNode
			)
		) {
			return;
		}

		$items = $list->getItems();
		if ($items[count($items) - 1] !== $node) {
			return;
		}

		if ($context->report($node, 'Useless return at the end of the function')) {
			$node->remove();
		}
	}
}
