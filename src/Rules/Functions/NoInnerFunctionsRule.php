<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ClassLikeNode, FunctionLikeNode};
use PhpSyntax\Nodes\Statement\FunctionNode;


/**
 * A named function declared inside a function, method, closure or property hook; a method of a class declared
 * there does not count. Reported.
 */
#[RuleInfo(Stage::Structure)]
final class NoInnerFunctionsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('functions.innerFunctions', Domain::state('forbidden'), 'A named function declared inside a function, a method, a closure or a property hook')];
	}


	public function getVisitedNodes(): array
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
			} elseif ($ancestor instanceof FunctionLikeNode) {
				$context->report($node, "The function `{$node->name->text}()` declared inside another function is forbidden.", fixable: false);
				return;
			}
		}
	}
}
