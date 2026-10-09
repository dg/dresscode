<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Shapes;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\BinaryOpNode;


/**
 * The `!=` operator instead of its `<>` synonym.
 */
#[RuleInfo(Stage::Structure)]
final class NotEqualsNotationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('expressions.comparison.notEquals', new Shapes(['exclamation' => ['!=', '`!=`, never `<>`']]), 'The operator of inequality')];
	}


	public function getVisitedNodes(): array
	{
		return [BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof BinaryOpNode
			&& $node->operator->text === '<>'
			&& $context->report($node->operator, 'The `<>` operator must be written `!=`.')
		) {
			$node->operator->setText('!=');
		}
	}
}
