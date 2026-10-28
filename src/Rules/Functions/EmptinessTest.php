<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use PhpSyntax\Nodes\{ArgumentNode, ExpressionNode, NameNode};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, FunctionCallNode, ParenthesizedNode, UnaryOpNode};
use PhpSyntax\Nodes\Scalar\IntegerNode;
use function count;


/**
 * A test whether what a function measures is empty, `strlen($s) === 0`, `count($a) > 0` or `!count($a)`: the
 * comparisons that ask exactly that, with 0 or 1 on either side, and the negation, of a call by name with one
 * positional argument.
 * @internal
 */
final readonly class EmptinessTest
{
	private function __construct(
		public FunctionCallNode $call,
		public ExpressionNode $value,
		/** whether it asks for emptiness, as opposed to a value that is not empty */
		public bool $empty,
	) {
	}


	/** The test the comparison or the negation makes; null where it asks nothing of the sort. */
	public static function find(BinaryOpNode|UnaryOpNode $node): ?self
	{
		[$call, $empty] = $node instanceof BinaryOpNode
			? self::readComparison($node)
			: ($node->operator->is('!') ? [self::unwrap($node->expression), true] : [null, null]);
		return $call instanceof FunctionCallNode
			&& $empty !== null
			&& $call->name instanceof NameNode
			&& count($call->arguments->items) === 1
			&& ($argument = $call->arguments->items->getItems()[0]) instanceof ArgumentNode
			&& $argument->name === null
			&& $argument->ellipsis === null
			? new self($call, $argument->value, $empty)
			: null;
	}


	/**
	 * The call compared and whether the comparison asks for emptiness, true, or for a value that is not empty,
	 * false; nulls for a comparison that asks neither.
	 * @return array{?ExpressionNode, ?bool}
	 */
	private static function readComparison(BinaryOpNode $comparison): array
	{
		$operator = $comparison->operator->text;
		[$call, $number] = [self::unwrap($comparison->left), $comparison->right];
		if ($call instanceof IntegerNode) { // written in the other order, so the ordering reads mirrored
			[$call, $number] = [self::unwrap($comparison->right), $call];
			$operator = strtr($operator, ['<' => '>', '>' => '<']);
		}

		if (!$number instanceof IntegerNode) {
			return [null, null];
		}

		$empty = match ([$operator, $number->toValue()]) {
			['===', 0], ['==', 0], ['<=', 0], ['<', 1] => true,
			['!==', 0], ['!=', 0], ['>', 0], ['>=', 1] => false,
			default => null,
		};
		return [$call, $empty];
	}


	private static function unwrap(ExpressionNode $expression): ExpressionNode
	{
		return $expression instanceof ParenthesizedNode ? $expression->expression : $expression;
	}
}
