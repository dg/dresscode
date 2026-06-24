<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\ElseNode;
use PhpSyntax\Nodes\Statement\IfNode;


/**
 * The `elseif` keyword instead of `else if`; the branches of the inner `if` move to the outer one.
 * A comment between `else` and `if` keeps the pair apart.
 */
#[RuleInfo(
	'dresscode/elseif-keyword',
	Stage::Structure,
	description: 'Replaces else if with elseif',
)]
final class ElseifKeywordRule extends NodeRule
{
	public function getVisitedTypes(): array
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
			|| !$context->report($node, "An 'else if' must be written 'elseif'")
		) {
			return;
		}

		$template = (new Parser)->parseStatement('if (0) {} elseif (0) {}');
		assert($template instanceof IfNode);
		$branch = clone $template->elseifs->getItems()[0];
		$branch->openParen = clone $inner->openParen;
		$branch->condition = clone $inner->condition;
		$branch->closeParen = clone $inner->closeParen;
		$branch->body = clone $inner->body;
		$branch->elseifKeyword->setLeadingTrivia($node->elseKeyword->leadingTrivia);
		$branch->elseifKeyword->setTrailingTrivia($inner->ifKeyword->trailingTrivia);

		$tail = array_map(fn($elseif) => clone $elseif, $inner->elseifs->getItems());
		$else = $inner->else ? clone $inner->else : null;
		$outer->else = $else;
		$outer->elseifs->append($branch);
		foreach ($tail as $elseif) {
			$outer->elseifs->append($elseif);
		}
	}
}
