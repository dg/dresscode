<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, TokenKind};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, ParenthesizedNode};
use PhpSyntax\Nodes\ExpressionNode;


/**
 * Parentheses where the precedence of operators is easy to misread: around an operand of a logical
 * operator that is itself a different logical operation (`$a && $b || $c`), and around a comparison that
 * is an operand of a bitwise operator (`$a & $b == 1`, which PHP reads as `$a & ($b == 1)`). The parentheses
 * follow the way PHP already reads the expression, so its meaning never changes.
 */
#[RuleInfo(
	'dresscode/explicit-operator-precedence',
	Stage::Structure,
	description: 'Parenthesizes an operand where the precedence of logical or bitwise operators is easy to misread',
)]
final class ExplicitOperatorPrecedenceRule extends NodeRule
{
	private const Logical = [
		TokenKind::BooleanAnd,
		TokenKind::BooleanOr,
		TokenKind::LogicalAnd,
		TokenKind::LogicalOr,
		TokenKind::LogicalXor,
	];
	private const Bitwise = ['&', '|', '^'];
	private const Comparison = [
		TokenKind::IsEqual, TokenKind::IsNotEqual, TokenKind::IsIdentical, TokenKind::IsNotIdentical,
		'<', '>', TokenKind::IsSmallerOrEqual, TokenKind::IsGreaterOrEqual, TokenKind::Spaceship,
	];


	public function getVisitedTypes(): array
	{
		return [BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BinaryOpNode) {
			return;
		}

		foreach ([$node->left, $node->right] as $operand) {
			if ($operand instanceof BinaryOpNode && self::isAmbiguous($node->operator, $operand->operator)) {
				$this->parenthesize($operand, $context);
			}
		}
	}


	private static function isAmbiguous(Token $outer, Token $inner): bool
	{
		return ($outer->is(...self::Logical) && $inner->is(...self::Logical) && $outer->kind !== $inner->kind)
			|| ($outer->is(...self::Bitwise) && $inner->is(...self::Comparison));
	}


	private function parenthesize(ExpressionNode $operand, RuleContext $context): void
	{
		if ($context->report($operand, 'Parentheses must make the precedence of the operators explicit')) {
			$operand->replaceWith(ParenthesizedNode::of($operand->withoutEdgeTrivia()));
		}
	}
}
