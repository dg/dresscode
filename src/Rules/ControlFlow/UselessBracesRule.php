<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\PlainNodeList;
use PhpSyntax\Nodes\Statement\BlockNode;


/**
 * No braces around a group of statements that is not the body of anything; the indentation of the
 * freed statements is left to dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/uselessBraces',
	Stage::Structure,
	description: 'Removes braces around a bare statement group',
	group: RuleGroup::Cleanup,
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
			|| !$node->parent instanceof PlainNodeList
			|| !$context->report($node, 'Useless braces')
		) {
			return;
		}

		$node->unwrap();
	}
}
