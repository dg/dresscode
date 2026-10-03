<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClosureNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\{ContinueNode, DoWhileNode, ForeachNode, ForNode, FunctionNode, SwitchNode, WhileNode};


/**
 * A bare `continue` whose innermost enclosing structure is a `switch` acts as `break` and is written so.
 */
#[RuleInfo(
	'dresscode/noContinueInSwitch',
	Stage::Structure,
	description: 'Leaves a `switch` with `break`, never with `continue`',
	group: RuleGroup::Correctness,
)]
final class NoContinueInSwitchRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ContinueNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ContinueNode || $node->level !== null) {
			return;
		}

		for ($ancestor = $node->parent; $ancestor !== null; $ancestor = $ancestor->parent) {
			if ($ancestor instanceof SwitchNode) {
				break;
			} elseif (
				$ancestor instanceof ForNode
				|| $ancestor instanceof ForeachNode
				|| $ancestor instanceof WhileNode
				|| $ancestor instanceof DoWhileNode
				|| $ancestor instanceof FunctionNode
				|| $ancestor instanceof MethodNode
				|| $ancestor instanceof ClosureNode
				|| $ancestor instanceof ArrowFunctionNode
			) {
				return;
			}
		}

		if (
			$ancestor instanceof SwitchNode
			&& $context->report($node, 'A switch must be left with `break`, not `continue`')
		) {
			$node->replaceWith((new Builder)->statement('break;'));
		}
	}
}
