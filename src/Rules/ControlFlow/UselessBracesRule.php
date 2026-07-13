<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\PlainNodeList;
use PhpSyntax\Nodes\Statement\BlockNode;


/**
 * No braces around a group of statements that is not the body of anything; the indentation of the
 * freed statements is left to `IndentationRule`.
 */
#[RuleInfo(Stage::Structure)]
final class UselessBracesRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('braces.bareStatementGroup', Domain::state('forbidden'), 'No `{ … }` around statements that nothing opens')];
	}


	public function getVisitedNodes(): array
	{
		return [BlockNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof BlockNode
			|| !$node->parent instanceof PlainNodeList
			|| self::isNextToOutput($node)
			|| !$context->report($node, 'Useless braces, because they open no construct.')
		) {
			return;
		}

		$node->unwrap();
	}


	/** Whether a brace stands next to a close tag or inline HTML, where the whitespace it leaves would be output. */
	private static function isNextToOutput(BlockNode $block): bool
	{
		return array_any(
			[$block->openBrace, $block->closeBrace],
			fn(Token $brace) => $brace->getPrevious()?->is([Token::CloseTag, Token::InlineHtml]) || $brace->getNext()?->is(Token::CloseTag),
		);
	}
}
