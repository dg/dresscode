<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Variables;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\PlainNodeList;
use PhpSyntax\Nodes\Statement\UnsetNode;


/**
 * Consecutive `unset()` statements become one call; a comment between or inside the later ones
 * stops the merge at that point.
 */
#[RuleInfo(
	'dresscode/combinedUnsets',
	Stage::Structure,
	description: 'Drops several variables in one `unset` instead of consecutive statements',
	group: RuleGroup::Modernization,
)]
final class CombinedUnsetsRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [UnsetNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UnsetNode || !($list = $node->parent) instanceof PlainNodeList) {
			return;
		}

		$items = $list->getItems();
		$index = $list->indexOf($node);
		if (isset($items[$index - 1]) && $items[$index - 1] instanceof UnsetNode) {
			return; // merged into the first of the run
		}

		while (
			($next = $list->getItems()[$list->indexOf($node) + 1] ?? null) instanceof UnsetNode
			&& !$node->semicolon->hasComment()
			&& !$node->semicolon->hasCommentUpTo($next->getLastToken())
			&& !$next->getLastToken()->hasComment()
			&& $context->report($next, 'Consecutive `unset` statements must be combined into one')
		) {
			foreach ($next->variables->getItems() as $var) {
				$node->variables->append(clone $var);
			}

			$next->remove();
		}
	}
}
