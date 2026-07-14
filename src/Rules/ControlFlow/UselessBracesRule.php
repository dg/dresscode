<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\BlockNode;


/**
 * No braces around a group of statements that is not the body of anything; the indentation of the
 * freed statements is left to dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/useless-braces',
	Stage::Structure,
	description: 'Removes braces around a bare statement group',
	group: Group::Cleanup,
)]
final class UselessBracesRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [BlockNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof BlockNode
			|| !($list = $node->parent) instanceof NodeList
			|| !$context->report($node, 'Useless braces')
		) {
			return;
		}

		$index = $list->indexOf($node);
		foreach ($node->statements->getItems() as $stmt) {
			$node->statements->removeItem($stmt);
			$list->insert(++$index, $stmt); // after the block, so that remove() hands the brace trivia to the first one
		}

		$node->remove();
	}
}
