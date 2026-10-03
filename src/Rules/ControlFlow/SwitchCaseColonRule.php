<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\CaseNode;


/**
 * A colon after `case` and `default`, not the semicolon PHP also accepts.
 */
#[RuleInfo(
	'dresscode/switchCaseColon',
	Stage::Structure,
	description: 'Ends `case` and `default` with a colon',
	group: RuleGroup::Deprecations,
)]
final class SwitchCaseColonRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [CaseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof CaseNode
			|| !$node->separator->is(';')
			|| !$context->report($node->separator, 'A case must end with a colon, not a semicolon')
		) {
			return;
		}

		$node->separator->replaceWith(Token::fromText(':'));
	}
}
