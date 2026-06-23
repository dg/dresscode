<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Shapes;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\ElseNode;
use PhpSyntax\Nodes\Statement\IfNode;


/**
 * The `elseif` keyword instead of `else if`; the branches of the inner `if` move to the outer one.
 * A comment between `else` and `if` keeps the pair apart.
 */
#[RuleInfo(Stage::Structure)]
final class ElseifNotationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('controlFlow.elseif', new Shapes(['oneWord' => ['elseif', '`elseif` in one word, never `else if`']]), 'The keyword of a branch that follows `if`')];
	}


	public function getVisitedNodes(): array
	{
		return [ElseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ElseNode
			|| !($inner = $node->body) instanceof IfNode
			|| $inner->body === null
			|| !($outer = $node->parent) instanceof IfNode
			|| $node->elseKeyword->hasCommentUpTo($inner->ifKeyword)
			|| !$context->report($node, 'An `else if` must be written `elseif`.')
		) {
			return;
		}

		// the else leaves the tree first, a node moving only out of a subtree that has no file
		$outer->else = null;
		$branch = (new Builder)->fragment(IfNode::class, 'if (0) {} elseif (0) {}')->elseifs->getItems()[0];
		$branch->openParen = $inner->openParen;
		$branch->condition = $inner->condition;
		$branch->closeParen = $inner->closeParen;
		$branch->body = $inner->body;
		$branch->elseifKeyword
			->setLeadingTrivia($node->elseKeyword->leadingTrivia)
			->setTrailingTrivia($inner->ifKeyword->trailingTrivia);

		$outer->elseifs->append($branch);
		foreach ($inner->elseifs->getItems() as $elseif) {
			$outer->elseifs->append($elseif);
		}

		$outer->else = $inner->else;
	}
}
