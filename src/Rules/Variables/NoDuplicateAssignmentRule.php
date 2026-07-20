<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Variables;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{AssignmentNode, ListNode, VariableNode};
use PhpSyntax\Nodes\ExpressionNode;


/**
 * `$a = $a = f()` assigns the same variable twice; reported.
 */
#[RuleInfo(
	'dresscode/no-duplicate-assignment',
	Stage::Structure,
	description: 'Reports an assignment repeated to the same variable in one expression',
	group: Group::Correctness,
)]
final class NoDuplicateAssignmentRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [AssignmentNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof AssignmentNode
			|| !($inner = $node->expression) instanceof AssignmentNode
			|| ($name = self::getVariableName($node->target)) === null
			|| $name !== self::getVariableName($inner->target)
		) {
			return;
		}

		$context->report($inner->target, "Duplicate assignment to variable $name", fixable: false);
	}


	private static function getVariableName(ExpressionNode|ListNode $expr): ?string
	{
		return $expr instanceof VariableNode && $expr->name instanceof Token && $expr->dollar === null
			? $expr->name->text
			: null;
	}
}
