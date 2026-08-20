<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\FunctionLikeNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Scalar\NullNode;
use PhpSyntax\Nodes\Statement\ReturnNode;


/**
 * `__debugInfo()` tells a dump what to show of the object, and null tells it nothing, which is what an empty
 * array says without the deprecation PHP 8.5 put on it. A bare `return` says null as well and is read the
 * same way; a return standing in a closure inside the method belongs to that closure and stays. Only a null
 * written out is rewritten: without the types, an expression giving null is not told from one giving an array.
 */
#[RuleInfo(
	'dresscode/no-null-debug-info-return',
	Stage::Structure,
	description: 'Returns an empty array from __debugInfo() where it returns null',
	group: Group::Correctness,
)]
final class NoNullDebugInfoReturnRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodNode
			|| strcasecmp($node->name->text, '__debugInfo') !== 0
			|| $node->body === null
		) {
			return;
		}

		foreach ($node->find(ReturnNode::class) as $return) {
			if (
				($return->expression !== null && !$return->expression instanceof NullNode)
				|| $return->findAncestor(FunctionLikeNode::class) !== $node
				|| $return->hasComment()
				|| !$context->report($return, 'The __debugInfo() method must return an array, not null')
			) {
				continue;
			}

			$replacement = (new Parser)->parseFragment(ReturnNode::class, 'return [];');
			$array = $replacement->expression;
			assert($array !== null);
			if ($return->expression === null) {
				$return->replaceWith($replacement);
			} else {
				$replacement->expression = null;
				$return->expression->replaceWith($array);
			}
		}
	}
}
