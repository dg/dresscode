<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayNode;
use PhpSyntax\Nodes\Expression\AssignmentNode;
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Nodes\Expression\CastNode;
use PhpSyntax\Nodes\Expression\CombinedAssignmentNode;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\Expression\UnaryOpNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar\BooleanNode;
use PhpSyntax\Nodes\Scalar\NullNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * A comparison has the variable on the left and the constant on the right: `$a === 1`, not `1 === $a`.
 * The sides are ranked as slevomat does: a variable ranks highest, a call next, a constant lowest, so
 * a comparison of two variables or of two calls is left alone. A right side that is an assignment is only
 * reported, because the swap would change what is assigned.
 */
#[RuleInfo(
	'dresscode/no-yoda-comparison',
	Stage::Structure,
	description: 'Puts the variable side of a comparison on the left',
)]
final class NoYodaComparisonRule extends NodeRule
{
	private const
		Variable = 3,
		Call = 2,
		Constant = 1,
		Literal = 0;


	public function getVisitedTypes(): array
	{
		return [BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof BinaryOpNode
			|| !$node->operator->is(TokenKind::IsEqual, TokenKind::IsNotEqual, TokenKind::IsIdentical, TokenKind::IsNotIdentical)
		) {
			return;
		}

		$left = self::rank($node->left);
		$right = self::rank($node->right);
		if (
			$left === null
			|| $right === null
			|| $left >= $right
			|| ($left >= self::Call && $right >= self::Call)
			|| !$context->report($node->operator, 'The variable of a comparison must be on the left side')
			|| $node->right instanceof AssignmentNode
			|| $node->right instanceof CombinedAssignmentNode
		) {
			return;
		}

		$newLeft = clone $node->right;
		$newRight = clone $node->left;
		self::keepEdges($node->left, $newLeft);
		self::keepEdges($node->right, $newRight);
		$node->left = $newLeft;
		$node->right = $newRight;
	}


	/**
	 * How dynamic a side is: a variable (also one behind a cast, a unary sign or as the start of a longer
	 * expression), a call or anything in parentheses, a constant, a literal; null for anything else.
	 */
	private static function rank(ExpressionNode $expr): ?int
	{
		while (($expr instanceof UnaryOpNode && $expr->operator->is('+', '-')) || $expr instanceof CastNode) {
			$expr = $expr->expression;
		}

		$first = $expr->getFirstToken();
		$last = $expr->getLastToken();
		if ($first === null || $last === null) {
			return null;
		}

		$beforeLast = $last->getPrevious();
		return match (true) {
			$first->kind === TokenKind::Variable => self::Variable,
			$last->is(')') => $expr instanceof ArrayNode ? self::Literal : self::Call,
			$expr instanceof BooleanNode, $expr instanceof NullNode => self::Literal,
			$expr instanceof ConstantFetchNode => self::Constant,
			$beforeLast?->is('::') && $last->kind === TokenKind::Variable => self::Variable,
			$beforeLast?->is('::') && $last->kind === TokenKind::Identifier => self::Constant,
			$first->is(TokenKind::Integer, TokenKind::Float, TokenKind::ConstantEncapsedString, TokenKind::Array, '[') => self::Literal,
			$first->kind === TokenKind::Identifier => self::Call,
			default => null,
		};
	}


	/** Gives the copy the trivia the original had on its edges, so that the spacing around the operator stays. */
	private static function keepEdges(ExpressionNode $original, ExpressionNode $copy): void
	{
		$copy->setEdgeTrivia($original->getFirstToken()->leadingTrivia ?? [], $original->getLastToken()->trailingTrivia ?? []);
	}
}
