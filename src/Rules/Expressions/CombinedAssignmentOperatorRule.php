<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayAccessNode;
use PhpSyntax\Nodes\Expression\AssignmentNode;
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Nodes\Expression\CombinedAssignmentNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Parser;
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
final class CombinedAssignmentOperatorRule extends NodeRule
{
	private const Operators = [
		'+' => '+=', '-' => '-=', '*' => '*=', '/' => '/=', '%' => '%=', '**' => '**=',
		'.' => '.=', '&' => '&=', '|' => '|=', '^' => '^=', '<<' => '<<=', '>>' => '>>=', '??' => '??=',
	];


	public function getVisitedTypes(): array
	{
		return [AssignmentNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof AssignmentNode
			|| !($var = $node->target) instanceof ExpressionNode
			|| $var instanceof ArrayAccessNode
			|| !($binary = $node->expression) instanceof BinaryOpNode
			|| ($combined = self::Operators[$binary->operator->text] ?? null) === null
			|| !$var->isRepeatableRead()
			|| !$var->matches($binary->left)
			|| ($right = $binary->right->getFirstToken()) === null
			|| $node->operator->getLine() !== $right->getLine()
			|| $node->operator->hasCommentUpTo($right)
			|| !$context->report($node, "The assignment must be written '$combined' instead of repeating its target")
		) {
			return;
		}

		$replacement = (new Parser)->parseExpression('$x ' . $combined . ' 0');
		assert($replacement instanceof CombinedAssignmentNode);
		$replacement->operator->setLeadingTrivia($node->operator->leadingTrivia);
		$replacement->operator->setTrailingTrivia($node->operator->trailingTrivia);
		$replacement->target = clone $var;
		$replacement->expression = clone $binary->right;
		$replacement->setEdgeTrivia([], []);
		$node->replaceWith($replacement);
	}
}
