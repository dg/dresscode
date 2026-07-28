<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClosureNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;


/**
 * A named function declared inside a function, method or closure; a method of a class declared there
 * does not count. Reported.
 */
#[RuleInfo(
	'dresscode/no-inner-functions',
	Stage::Structure,
	description: 'Reports a function declared inside another function',
)]
final class NoInnerFunctionsRule extends NodeRule
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
