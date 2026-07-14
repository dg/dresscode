<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\FunctionLikeNode;
use PhpSyntax\Nodes\Statement\{ContinueNode, DoWhileNode, ForeachNode, ForNode, SwitchNode, WhileNode};


/**
 * A bare `continue` whose innermost enclosing structure is a `switch` acts as `break`. Whether `break` was meant or
 * `continue 2`, which PHP warns about, only the author knows, so it is reported and left as it is.
 */
#[RuleInfo(Stage::Structure)]
final class NoContinueInSwitchRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.continueInSwitch', Domain::state('forbidden'), 'A `continue` that only leaves a `switch` is `break`')];
	}


	public function getVisitedNodes(): array
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
				|| $ancestor instanceof FunctionLikeNode
			) {
				return;
			}
		}

		if ($ancestor instanceof SwitchNode) {
			$context->report($node, 'A switch must be left with `break`, or the loop continued with `continue 2`, not `continue`.', fixable: false);
		}
	}
}
