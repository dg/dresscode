<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\PlainNodeList;
use PhpSyntax\Nodes\Statement\EmptyStatementNode;


/**
 * No lone semicolon standing for an empty statement; the one a close tag forms stays.
 */
#[RuleInfo(
	'dresscode/noEmptyStatements',
	Stage::Structure,
	description: 'Removes empty statements',
	group: RuleGroup::Cleanup,
)]
final class NoEmptyStatementsRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [EmptyStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof EmptyStatementNode
			&& $node->parent instanceof PlainNodeList
			&& !$node->semicolon->is(Token::CloseTag)
			&& $context->report($node, 'Empty statement')
		) {
			$node->remove();
		}
	}
}
