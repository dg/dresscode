<?php declare(strict_types=1);

namespace DressCode\Rules\Variables;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\AssignNode;
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
final class NoDuplicateAssignmentRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [AssignNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof AssignNode
			|| !$node->operator->is('=')
			|| !($inner = $node->expr) instanceof AssignNode
			|| !$inner->operator->is('=')
			|| ($name = self::getVariableName($node->var)) === null
			|| $name !== self::getVariableName($inner->var)
		) {
			return;
		}

		$context->report($inner->var, "Duplicate assignment to variable $name");
	}


	private static function getVariableName(ExpressionNode $expr): ?string
	{
		return $expr instanceof VariableNode && $expr->name instanceof Token && $expr->dollar === null
			? $expr->name->text
			: null;
	}
}
