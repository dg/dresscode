<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Token;


/**
 * No braces around a group of statements that is not the body of anything; the indentation of the
 * freed statements is left to the indentation rule.
 */
#[RuleInfo(
	'dresscode/useless-braces',
	Stage::Structure,
	description: 'Removes braces around a bare statement group',
)]
final class UselessBracesRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [BlockNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof BlockNode
			|| !($list = $node->parent) instanceof NodeList
			|| !$context->report($node, 'Useless braces')
		) {
			return;
		}

		$index = $list->indexOf($node);
		foreach ($node->stmts->getItems() as $stmt) {
			$node->stmts->removeItem($stmt);
			$list->insert(++$index, $stmt); // after the block, so that remove() hands the brace trivia to the first one
		}

		$node->remove();
	}
}
