<?php declare(strict_types=1);

namespace DressCode\Rules\Variables;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\AssignmentNode;
use PhpSyntax\Nodes\Expression\ListNode;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Token;


/**
 * `$a = $a = f()` assigns the same variable twice; reported.
 */
#[RuleInfo(
	'dresscode/no-duplicate-assignment',
	Stage::Structure,
	description: 'Reports an assignment repeated to the same variable in one expression',
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

		$context->report($inner->target, "Duplicate assignment to variable $name");
	}


	private static function getVariableName(ExpressionNode|ListNode $expr): ?string
	{
		return $expr instanceof VariableNode && $expr->name instanceof Token && $expr->dollar === null
			? $expr->name->text
			: null;
	}
}
