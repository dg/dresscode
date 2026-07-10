<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Token, TokenKind};
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
#[RuleInfo(
	'dresscode/yoda',
	Stage::Structure,
	description: 'Decides which side of a comparison the constant stands on',
	decision: 'comparisons',
)]
final class YodaRule extends NodeRule implements ConfigurableRule
{
	private const Forbidden = 'forbidden';
	private const Required = 'required';

	private const
		Variable = 3,
		Call = 2,
		Constant = 1,
		Literal = 0;

	private string $comparisons = self::Forbidden;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'comparisons' => Expect::anyOf(self::Forbidden, self::Required)->default(self::Forbidden)
				->description('forbidden puts the variable of a comparison on the left, required puts the constant there'),
		]);
	}


	public function configure(array $options): void
	{
		$this->comparisons = $options['comparisons'];
	}


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
		$wanted = $this->comparisons === self::Forbidden ? 'variable' : 'constant';
		if (
			$left === null
			|| $right === null
			|| ($this->comparisons === self::Forbidden ? $left >= $right : $left <= $right)
			|| ($left >= self::Call && $right >= self::Call)
			|| !$context->report($node->operator, "The $wanted of a comparison must be on the left side")
			|| self::isAssignment($node->left)
			|| self::isAssignment($node->right)
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


	/** An assignment that changed sides would assign something else, so such a comparison is only reported. */
	private static function isAssignment(ExpressionNode $expr): bool
	{
		return $expr instanceof AssignmentNode || $expr instanceof CombinedAssignmentNode;
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
