<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayDimFetchNode;
use PhpSyntax\Nodes\Expression\AssignNode;
use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;


/**
 * The combined operator for an assignment that repeats its target as the left operand:
 * `$a += $b`, not `$a = $a + $b`; only for targets free of side effects. An offset target stays,
 * because on a string offset the combined operator is a compile error.
 */
#[RuleInfo(
	'dresscode/combined-assignment-operator',
	Stage::Structure,
	description: 'Uses += and friends where an assignment repeats its target',
)]
final class CombinedAssignmentOperatorRule extends Rule
{
	private const Operators = [
		'+' => '+=', '-' => '-=', '*' => '*=', '/' => '/=', '%' => '%=', '**' => '**=',
		'.' => '.=', '&' => '&=', '|' => '|=', '^' => '^=', '<<' => '<<=', '>>' => '>>=', '??' => '??=',
	];


	public function getVisitedTypes(): array
	{
		return [AssignNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$right = $node instanceof AssignNode && $node->expr instanceof BinaryNode
			? $node->expr->right->getFirstToken()
			: null;
		if (
			!$node instanceof AssignNode
			|| !$node->operator->is('=')
			|| $node->var instanceof ArrayDimFetchNode
			|| !($binary = $node->expr) instanceof BinaryNode
			|| ($combined = self::Operators[$binary->operator->text] ?? null) === null
			|| !$node->var->isRepeatableRead()
			|| !$node->var->matches($binary->left)
			|| $right === null
			|| $node->operator->getLine() !== $right->getLine()
			|| $node->operator->hasCommentUpTo($right)
			|| !$context->report($node, "The assignment must be written $combined instead of repeating its target")
		) {
			return;
		}

		$template = (new Parser)->parseExpression('$x ' . $combined . ' 0');
		assert($template instanceof AssignNode);
		$operator = clone $template->operator;
		$operator->setLeadingTrivia($node->operator->leadingTrivia);
		$operator->setTrailingTrivia($node->operator->trailingTrivia);
		$node->setOperator($operator);
		$node->setExpr(clone $binary->right);
	}
}
