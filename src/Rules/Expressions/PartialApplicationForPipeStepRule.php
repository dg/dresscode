<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentListNode, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, FunctionCallNode};


/**
 * A step of the pipe operator written as a first-class callable, `$s |> strtolower(...)`, is the partial application
 * of PHP 8.6, `$s |> strtolower(?)`, which shows where the value goes, as a step with arguments of its own has to,
 * `str_replace('a', 'b', ?)`. PHP compiles both into a plain call of the function, so the code does the same.
 */
#[RuleInfo(
	Stage::Structure,
	requires: ['php' => '>=8.6'],
	decisions: ['upgrading.syntax.pipe'],
)]
final class PartialApplicationForPipeStepRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [VariadicPlaceholderNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof VariadicPlaceholderNode) {
			return;
		}

		$arguments = $node->findAncestor(ArgumentListNode::class);
		$call = $arguments?->parent;
		$pipe = $call?->parent;
		if (
			$arguments?->items->count() === 1
			&& $pipe instanceof BinaryOpNode
			&& $pipe->right === $call
			&& $pipe->operator->is('|>')
			&& $context->report($node, 'The step of the pipe operator must be written as a partial application.')
		) {
			$node->replaceWith(
				(new Builder)->fragment(FunctionCallNode::class, 'f(?)')->arguments->items->getItems()[0]->withoutEdgeTrivia(),
			);
		}
	}
}
