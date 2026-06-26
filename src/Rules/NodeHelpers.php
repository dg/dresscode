<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use PhpSyntax\{Node, Token, TokenKind};
use PhpSyntax\Nodes\{ElseIfNode, Expression, Statement};


/**
 * Queries and constructions over the tree that rules share.
 * @internal
 */
final class NodeHelpers
{
	private const LogicalOperators = [
		TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor,
	];


	/**
	 * The if, elseif, while or do-while whose condition the operation is a part of, reached from the condition down
	 * through logical operators alone, not through parentheses or a negation; null for any other operation.
	 */
	public static function findConditionStatement(
		Expression\BinaryOpNode $operation,
	): Statement\IfNode|ElseIfNode|Statement\WhileNode|Statement\DoWhileNode|null
	{
		for ($node = $operation; self::isLogicalOperation($node); $node = $parent) {
			$parent = $node->parent;
			if (
				$parent instanceof Statement\IfNode
				|| $parent instanceof ElseIfNode
				|| $parent instanceof Statement\WhileNode
				|| $parent instanceof Statement\DoWhileNode
			) {
				return $parent->condition === $node ? $parent : null;
			}
		}

		return null;
	}


	/**
	 * Whether the node is an operation of `&&`, `||`, `and`, `or` or `xor`, the operators the parts of a condition
	 * are chained with.
	 * @phpstan-assert-if-true Expression\BinaryOpNode $node
	 */
	public static function isLogicalOperation(?Node $node): bool
	{
		return $node instanceof Expression\BinaryOpNode && $node->operator->is(...self::LogicalOperators);
	}


	/** Whether a single line break follows the token, with nothing but whitespace between it and the next token. */
	public static function isLineBrokenAfter(Token $token): bool
	{
		$next = $token->getNext();
		if ($next === null || $token->hasCommentUpTo($next)) {
			return false;
		}

		$breaks = 0;
		foreach ([...$token->trailingTrivia, ...$next->leadingTrivia] as $trivia) {
			$breaks += (int) $trivia->isEndOfLine();
		}

		return $breaks === 1;
	}
}
