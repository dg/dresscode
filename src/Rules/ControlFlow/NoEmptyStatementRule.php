<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, TokenKind};
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\EmptyStatementNode;


/**
 * No lone semicolon standing for an empty statement; the one a close tag forms stays.
 */
#[RuleInfo(
	'dresscode/no-empty-statement',
	Stage::Structure,
	description: 'Removes empty statements',
	group: Group::Cleanup,
)]
final class NoEmptyStatementRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [EmptyStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof EmptyStatementNode
			&& $node->parent instanceof NodeList
			&& !$node->semicolon->is(TokenKind::CloseTag)
			&& $context->report($node, 'Empty statement')
		) {
			$node->remove();
		}
	}
}
