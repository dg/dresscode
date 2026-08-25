<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\UnaryOpNode;


/**
 * The `@` operator hides an error instead of handling it, so a failure leaves no trace. There is no fix: the code
 * has to test the condition or catch the failure itself.
 */
#[RuleInfo(Stage::Structure)]
final class NoErrorSuppressionRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.errorSuppression', Domain::state('forbidden'), 'The `@` operator, which silences errors')];
	}


	public function getVisitedNodes(): array
	{
		return [UnaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof UnaryOpNode && $node->operator->is('@')) {
			$context->report($node->operator, 'The `@` operator must not be used.', fixable: false);
		}
	}
}
