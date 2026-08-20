<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{FinallyNode, FunctionLikeNode};
use PhpSyntax\Nodes\Statement\ReturnNode;


/**
 * PHP 8.6 deprecated a `return` inside `finally`, bare or with a value, which throws away the exception or the
 * value the `try` was leaving with. The rule reports it and rewrites nothing, since what the block meant to do with
 * either is the author's to say. A `return` in a closure inside the block belongs to the closure.
 */
#[RuleInfo(Stage::Structure)]
final class NoReturnsInFinallyRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.php.returnInFinally', Domain::state('forbidden'), 'A `return` inside `finally`, which PHP 8.6 deprecated')];
	}


	public function getVisitedNodes(): array
	{
		return [FinallyNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FinallyNode) {
			return;
		}

		$function = $node->findAncestor(FunctionLikeNode::class);
		foreach ($node->find(ReturnNode::class) as $return) {
			if (
				$return->findAncestor(FinallyNode::class) === $node // an inner finally reports its own
				&& $return->findAncestor(FunctionLikeNode::class) === $function
			) {
				$context->report($return, 'A `return` inside `finally` is deprecated since PHP 8.6.', fixable: false);
			}
		}
	}
}
