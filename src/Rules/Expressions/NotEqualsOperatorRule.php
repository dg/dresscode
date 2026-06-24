<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\BinaryOpNode;


/**
 * The `!=` operator instead of its `<>` synonym.
 */
#[RuleInfo(
	'dresscode/not-equals-operator',
	Stage::Structure,
	description: 'Writes != instead of <>',
)]
final class NotEqualsOperatorRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof BinaryOpNode
			&& $node->operator->text === '<>'
			&& $context->report($node->operator, "The <> operator must be written '!='")
		) {
			$node->operator->setText('!=');
		}
	}
}
