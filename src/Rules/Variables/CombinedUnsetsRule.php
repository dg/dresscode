<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Variables;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\UnsetNode;


/**
 * Consecutive `unset()` statements become one call; a comment between or inside the later ones
 * stops the merge at that point.
 */
#[RuleInfo(
	'dresscode/combined-unsets',
	Stage::Structure,
	description: 'Drops several variables in one unset instead of consecutive statements',
	group: Group::Modernization,
)]
final class CombinedUnsetsRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [UnsetNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UnsetNode || !($list = $node->parent) instanceof NodeList) {
			return;
		}

		$items = $list->getItems();
		$index = $list->indexOf($node);
		if (isset($items[$index - 1]) && $items[$index - 1] instanceof UnsetNode) {
			return; // merged into the first of the run
		}

		while (
			($next = $list->getItems()[$list->indexOf($node) + 1] ?? null) instanceof UnsetNode
			&& ($last = $next->getLastToken()) !== null
			&& !$node->semicolon->hasComment()
			&& !$node->semicolon->hasCommentUpTo($last)
			&& !$last->hasComment()
			&& $context->report($next, 'Consecutive unset statements must be combined into one')
		) {
			foreach ($next->variables->getItems() as $var) {
				$node->variables->append(clone $var);
			}

			$next->remove();
		}
	}
}
