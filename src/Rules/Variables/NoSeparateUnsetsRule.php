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

		$previous = $node->getPreviousSibling();
		if ($previous instanceof UnsetNode && self::canMerge($previous, $node)) {
			return; // merged into the first of the run
		}

		while (
			($next = $node->getNextSibling()) instanceof UnsetNode
			&& self::canMerge($node, $next)
			&& $context->report($next, 'Consecutive `unset` statements must be combined into one.')
		) {
			foreach ($next->variables->getItems() as $var) {
				$node->variables->append($var->withoutEdgeTrivia());
			}

			$next->remove();
		}
	}


	/** Whether no comment stands between the two statements or inside the second one. */
	private static function canMerge(UnsetNode $node, UnsetNode $next): bool
	{
		return !$node->semicolon->hasComment()
			&& !$node->semicolon->hasCommentUpTo($next->getLastToken())
			&& !$next->getLastToken()->hasComment();
	}
}
