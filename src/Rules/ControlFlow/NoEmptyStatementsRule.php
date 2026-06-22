<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\PlainNodeList;
use PhpSyntax\Nodes\Statement\EmptyStatementNode;


/**
 * No lone semicolon standing for an empty statement; the one a close tag forms stays.
 */
#[RuleInfo(Stage::Structure)]
final class NoEmptyStatementsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('controlFlow.emptyStatement', Domain::state('forbidden'), 'The second `;` of `foo();;`')];
	}


	public function getVisitedNodes(): array
	{
		return [EmptyStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof EmptyStatementNode
			&& $node->parent instanceof PlainNodeList
			&& !$node->semicolon->is(Token::CloseTag)
			&& $context->report($node, 'Empty statement.')
		) {
			$node->remove();
		}
	}
}
