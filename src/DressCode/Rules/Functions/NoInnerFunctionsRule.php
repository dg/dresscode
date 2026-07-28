<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Expression\ArrowFunctionNode;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Token;


/**
 * A named function declared inside a function, method or closure; a method of a class declared there
 * does not count. Reported.
 */
#[RuleInfo(
	'dresscode/no-inner-functions',
	Stage::Structure,
	description: 'Reports a function declared inside another function',
)]
final class NoInnerFunctionsRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [FunctionNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FunctionNode) {
			return;
		}

		for ($ancestor = $node->parent; $ancestor !== null; $ancestor = $ancestor->parent) {
			if ($ancestor instanceof ClassLikeNode) {
				return;
			} elseif (
				$ancestor instanceof FunctionNode
				|| $ancestor instanceof MethodNode
				|| $ancestor instanceof ClosureNode
				|| $ancestor instanceof ArrowFunctionNode
			) {
				$context->report($node, 'A function declared inside another function is forbidden');
				return;
			}
		}
	}
}
