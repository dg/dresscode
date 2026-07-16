<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Variables;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{DestructuringNode, ExpressionNode};
use PhpSyntax\Nodes\Expression\{AssignmentNode, VariableNode};


/**
 * `$a = $a = f()` assigns the same variable twice; reported.
 */
#[RuleInfo(Stage::Structure)]
final class NoRepeatedAssignmentsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.repeatedAssignment', Domain::state('forbidden'), 'A variable assigned twice in one expression, `$a = $a = 1`')];
	}


	public function getVisitedNodes(): array
	{
		return [AssignmentNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof AssignmentNode
			|| !($inner = $node->expression) instanceof AssignmentNode
			|| ($name = self::findVariableName($node->target)) === null
			|| $name !== self::findVariableName($inner->target)
		) {
			return;
		}

		$context->report($inner->target, "The variable `$name` is assigned twice in one expression.", fixable: false);
	}


	private static function findVariableName(ExpressionNode|DestructuringNode $expr): ?string
	{
		return $expr instanceof VariableNode && $expr->name instanceof Token && $expr->dollar === null
			? $expr->name->text
			: null;
	}
}
