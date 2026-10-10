<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{ArrayNode, AssignmentNode, BinaryOpNode, CastNode, CombinedAssignmentNode, ConstantFetchNode, UnaryOpNode};
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar\{BooleanNode, NullNode};


/**
 * Which side of a comparison holds the constant: with `forbidden` the variable stands on the left and the
 * constant on the right (`$a === 1`), with `required` the other way round, which is what a standard asks
 * for when an accidental assignment must not compile. The sides are ranked: a variable highest, then a call,
 * a constant and a literal, so a comparison of two sides that are each a variable or a call is left alone,
 * and a side that is an assignment is only reported, because the swap would change what is assigned.
 */
#[RuleInfo(Stage::Structure)]
final class YodaRule extends NodeRule
{
	private const Forbidden = 'forbidden';
	private const Required = 'required';

	private const
		Variable = 3,
		Call = 2,
		Constant = 1,
		Literal = 0;

	private string $yoda = self::Forbidden;


	public static function getDecisions(): array
	{
		return [
			new Decision('expressions.comparison.yoda', new Words([
				self::Forbidden => 'the variable on the left and the constant on the right, `$a === 1`',
				self::Required => 'the constant on the left, `1 === $a`, so that an accidental assignment does not compile',
			]), 'Which side of a comparison holds the constant, a comparison of two variables or calls staying as it is'),
		];
	}


	public function configure(Values $values): void
	{
		$this->yoda = $values->get('expressions.comparison.yoda')->getWord();
	}


	public function getVisitedNodes(): array
	{
		return [BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof BinaryOpNode
			|| !$node->operator->is([Token::IsEqual, Token::IsNotEqual, Token::IsIdentical, Token::IsNotIdentical])
		) {
			return;
		}

		$left = self::rank($node->left);
		$right = self::rank($node->right);
		if (
			$left === null
			|| $right === null
			|| ($this->yoda === self::Forbidden ? $left >= $right : $left <= $right)
			|| ($left >= self::Call && $right >= self::Call)
		) {
			return;
		}

		// a literal is the constant the decision speaks of, and so is the less dynamic side of a call
		if (!$context->report(
			$node->operator,
			'The constant of a comparison must be on the ' . ($this->yoda === self::Forbidden ? 'right' : 'left') . ' side.',
			fixable: !self::isAssignment($node->left) && !self::isAssignment($node->right),
		)) {
			return;
		}

		$newLeft = $node->right->withoutEdgeTrivia();
		$newRight = $node->left->withoutEdgeTrivia();
		$node->left->replaceWithExpression($newLeft);
		$node->right->replaceWithExpression($newRight);
	}


	/** An assignment that changed sides would assign something else, so such a comparison is only reported. */
	private static function isAssignment(ExpressionNode $expr): bool
	{
		return $expr instanceof AssignmentNode || $expr instanceof CombinedAssignmentNode;
	}


	/**
	 * How dynamic a side is: a variable (also one behind a cast, a unary sign or as the start of a longer
	 * expression), a call or anything in parentheses, a constant, a literal; an operation as its most dynamic operand;
	 * null for anything else.
	 */
	private static function rank(ExpressionNode $expr): ?int
	{
		while (($expr instanceof UnaryOpNode && $expr->operator->is(['+', '-'])) || $expr instanceof CastNode) {
			$expr = $expr->expression;
		}

		if ($expr instanceof BinaryOpNode) {
			$left = self::rank($expr->left);
			$right = self::rank($expr->right);
			return $left === null || $right === null ? null : max($left, $right);
		}

		$first = $expr->getFirstToken();
		$last = $expr->getLastToken();
		$beforeLast = $last->getPrevious();
		return match (true) {
			$first->is(Token::Variable) => self::Variable,
			$last->is(')') => $expr instanceof ArrayNode ? self::Literal : self::Call,
			$expr instanceof BooleanNode, $expr instanceof NullNode => self::Literal,
			$expr instanceof ConstantFetchNode => self::Constant,
			$beforeLast?->is('::') && $last->is(Token::Variable) => self::Variable,
			$beforeLast?->is('::') && $last->is(Token::Identifier) => self::Constant,
			$first->is([Token::Integer, Token::Float, Token::ConstantEncapsedString, Token::Array, '[']) => self::Literal,
			$first->is(Token::Identifier) => self::Call,
			default => null,
		};
	}
}
