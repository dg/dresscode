<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{FunctionLikeNode, PlainNodeList};
use PhpSyntax\Nodes\Statement\{BlockNode, ReturnNode};
use function count;


/**
 * A bare `return;` as the last statement of a function body does what the closing brace does anyway.
 */
#[RuleInfo(Stage::Structure)]
final class UselessReturnRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('functions.trailingBareReturn', Domain::state('forbidden'), '`return;` as the last statement')];
	}


	public function getVisitedNodes(): array
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
			|| !$list instanceof PlainNodeList
			|| !$block instanceof BlockNode
			|| !$owner instanceof FunctionLikeNode
		) {
			return;
		}

		$items = $list->getItems();
		if ($items[count($items) - 1] !== $node) {
			return;
		}

		if ($context->report($node, 'Useless return, because the function ends right after it.')) {
			$node->remove();
		}
	}
}
