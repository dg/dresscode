<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyHookNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\ReturnNode;
use PhpSyntax\Token;
use function count;


/**
 * A bare `return;` as the last statement of a function body does what the closing brace does anyway.
 */
#[RuleInfo(
	'dresscode/useless-return',
	Stage::Structure,
	description: 'Removes a bare return at the end of a function body',
)]
final class UselessReturnRule extends Rule
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
			|| $node->expr !== null
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
