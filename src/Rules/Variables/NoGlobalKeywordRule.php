<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Variables;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Statement\GlobalNode;


/**
 * The global statement is reported; there is no safe automatic fix.
 */
#[RuleInfo(
	'dresscode/no-global-keyword',
	Stage::Structure,
	description: 'Forbids the global statement',
)]
final class NoGlobalKeywordRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [GlobalNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'The global statement is forbidden', fixable: false);
	}
}
