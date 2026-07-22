<?php declare(strict_types=1);

namespace DressCode\Rules\Variables;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\UnsetNode;
use PhpSyntax\Token;


/**
 * Consecutive `unset()` statements become one call; a comment between or inside the later ones
 * stops the merge at that point.
 */
#[RuleInfo(
	'dresscode/combined-unsets',
	Stage::Structure,
	description: 'Drops several variables in one unset instead of consecutive statements',
)]
final class CombinedUnsetsRule extends Rule
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
			foreach ($next->vars->getItems() as $var) {
				$node->vars->append(clone $var);
			}

			$next->remove();
		}
	}
}
