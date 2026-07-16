<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClosureNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\{ContinueNode, DoWhileNode, ForeachNode, ForNode, FunctionNode, SwitchNode, WhileNode};


/**
 * A bare `continue` whose innermost enclosing structure is a `switch` acts as `break` and is written so.
 */
#[RuleInfo(
	'dresscode/no-continue-in-switch',
	Stage::Structure,
	description: 'Leaves a switch with break, never with continue',
	group: Group::Correctness,
)]
final class NoContinueInSwitchRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ContinueNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ContinueNode || $node->expression !== null) {
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
			&& $context->report($node, "A switch must be left with 'break', not 'continue'")
		) {
			$node->replaceWith((new Parser)->parseStatement('break;'));
		}
	}
}
