<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Variables;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Statement\GlobalNode;


/**
 * The `global` statement is reported; there is no safe automatic fix.
 */
#[RuleInfo(Stage::Structure)]
final class NoGlobalStatementsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.globalStatement', Domain::state('forbidden'), 'The `global` statement')];
	}


	public function getVisitedNodes(): array
	{
		return [GlobalNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'The `global` statement is forbidden.', fixable: false);
	}
}
