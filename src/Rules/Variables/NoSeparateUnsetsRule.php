<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Variables;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\PlainNodeList;
use PhpSyntax\Nodes\Statement\UnsetNode;


/**
 * Consecutive `unset()` statements become one call; a comment between or inside the later ones
 * stops the merge at that point.
 */
#[RuleInfo(Stage::Structure)]
final class NoSeparateUnsetsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('expressions.separate.unset', Domain::state('forbidden'), '`unset($a); unset($b);` is `unset($a, $b);`')];
	}


	public function getVisitedNodes(): array
	{
		return [UnsetNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UnsetNode || !$node->parent instanceof PlainNodeList) {
			return;
		}

		if ($node->getPreviousSibling() instanceof UnsetNode) {
			return; // merged into the first of the run
		}

		while (
			($next = $node->getNextSibling()) instanceof UnsetNode
			&& !$node->semicolon->hasComment()
			&& !$node->semicolon->hasCommentUpTo($next->getLastToken())
			&& !$next->getLastToken()->hasComment()
			&& $context->report($next, 'Consecutive `unset` statements must be combined into one.')
		) {
			foreach ($next->variables->getItems() as $var) {
				$node->variables->append($var->withoutEdgeTrivia());
			}

			$next->remove();
		}
	}
}
