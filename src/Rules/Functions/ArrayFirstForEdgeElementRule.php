<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, ArrayItemNode, Expression, ExpressionNode, NameNode, NodeList, SeparatedNodeList, Statement};
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use function count;


/**
 * The first or the last element of an array is read with `array_first()` and `array_last()` of PHP 8.5,
 * however the code reaches for it today: `reset($a)`, `end($a)`, `$a[array_key_first($a)]`,
 * `$a[array_key_last($a)]`, `array_values($a)[0]` and `array_values($a)[count($a) - 1]`. An array the code
 * spells twice must be the same expression and free of side effects, because the call reads it once.
 *
 * `reset()` and `end()` are a risky subject: they move the internal pointer of the array, which the new
 * functions leave where it is, and they answer an empty array with false where the new ones answer null.
 * A call of theirs whose value nothing takes is left alone, being there for the pointer.
 */
#[RuleInfo(
	'dresscode/array-first-for-edge-element',
	Stage::Structure,
	description: 'Reads the first or the last element of an array with array_first() and array_last()',
	group: Group::Modernization,
	requires: ['php' => '>=8.5'],
)]
final class ArrayFirstForEdgeElementRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class, Expression\ArrayAccessNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ExpressionNode || $node->hasComment()) {
			return;
		}

		[$function, $array, $risky] = $node instanceof Expression\FunctionCallNode
			? $this->readPointerCall($node, $context)
			: $this->readOffset($node, $context);
		if ($array === null || $function === null) {
			return;
		}

		$uncertainty = $node instanceof Expression\FunctionCallNode ? NodeHelpers::findUncertainty($node, $context) : null;
		$element = $function === 'array_first' ? 'first' : 'last';
		if (!$context->report($node, "The $element item must be read with $function()" . $uncertainty, risky: $risky || $uncertainty !== null)) {
			return;
		}

		$name = NameNode::fromText($function);
		$node->replaceWith(Expression\FunctionCallNode::of($name, ArgumentListNode::of($array->withoutEdgeTrivia())));

		// the name is spelled once the call stands where the namespace can be read off it
		$name->text = NodeHelpers::spellGlobalFunction($function, $name, $context);
	}


	/**
	 * The array a call of `reset()` or `end()` reads the edge element of, where something takes the value.
	 * @return array{?string, ?ExpressionNode, bool}
	 */
	private function readPointerCall(Expression\FunctionCallNode $call, RuleContext $context): array
	{
		$arguments = self::readArguments($call, $context);
		if ($arguments === null || count($arguments) !== 1 || $call->parent instanceof ExpressionStatementNode) {
			return [null, null, false];
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$function = match (true) {
			$resolver->isGlobalFunctionCall($call, 'reset') => 'array_first',
			$resolver->isGlobalFunctionCall($call, 'end') => 'array_last',
			default => null,
		};
		return $function === null ? [null, null, false] : [$function, $arguments[0], true];
	}


	/**
	 * The array an offset access reads the edge element of, by the key the edge has or by the position it
	 * takes among the values.
	 * @return array{?string, ?ExpressionNode, bool}
	 */
	private function readOffset(ExpressionNode $access, RuleContext $context): array
	{
		$none = [null, null, false];
		// a call gives a value, never the place the code writes to or unsets
		if (!$access instanceof Expression\ArrayAccessNode || $access->index === null || self::isPlace($access)) {
			return $none;
		}

		// $a[array_key_first($a)]
		$keyed = self::readSingleArgument($access->index, ['array_key_first' => 'array_first', 'array_key_last' => 'array_last'], $context);
		if ($keyed !== null && self::repeats($keyed[1], $access->expression)) {
			return [$keyed[0], $access->expression, false];
		}

		// array_values($a)[0] and array_values($a)[count($a) - 1]
		$values = self::readSingleArgument($access->expression, ['array_values' => 'array_values'], $context);
		if ($values === null) {
			return $none;
		}

		$array = $values[1];
		if (self::isInteger($access->index, 0)) {
			return ['array_first', $array, false];
		}

		$counted = $access->index instanceof Expression\BinaryOpNode
			&& $access->index->operator->is('-')
			&& self::isInteger($access->index->right, 1)
			? self::readSingleArgument($access->index->left, ['count' => 'count'], $context)
			: null;
		return $counted !== null && self::repeats($counted[1], $array)
			? ['array_last', $array, false]
			: $none;
	}


	/**
	 * The function the call names, mapped by the given table, and its only argument.
	 * @param  array<string, string>  $functions
	 * @return ?array{string, ExpressionNode}
	 */
	private static function readSingleArgument(ExpressionNode $call, array $functions, RuleContext $context): ?array
	{
		$arguments = self::readArguments($call, $context);
		if ($arguments === null || count($arguments) !== 1) {
			return null;
		}

		assert($call instanceof Expression\FunctionCallNode);
		foreach ($functions as $name => $mapped) {
			if ($context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call, $name)) {
				return [$mapped, $arguments[0]];
			}
		}

		return null;
	}


	/**
	 * The values of the arguments of a call of a global function, null where the call is of another kind or
	 * names an argument, passes one by reference or unpacks one.
	 * @return ?list<ExpressionNode>
	 */
	private static function readArguments(ExpressionNode $call, RuleContext $context): ?array
	{
		if (
			!$call instanceof Expression\FunctionCallNode
			|| !$call->name instanceof NameNode
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call)
		) {
			return null;
		}

		$values = [];
		foreach ($call->arguments->items as $argument) {
			if (!$argument instanceof ArgumentNode || $argument->name || $argument->ampersand || $argument->ellipsis) {
				return null;
			}

			$values[] = $argument->value;
		}

		return $values;
	}


	/**
	 * Whether the expression stands where the code needs a place rather than a value: it is unset, assigned,
	 * stepped or taken by reference, whether directly or as what a longer access reaches through.
	 */
	private static function isPlace(ExpressionNode $expression): bool
	{
		$node = $expression;
		$parent = $node->parent;
		while (
			($parent instanceof Expression\ArrayAccessNode && $parent->expression === $node)
			|| ($parent instanceof Expression\PropertyFetchNode && $parent->object === $node)
			|| $parent instanceof Expression\ListNode
			|| $parent instanceof Expression\ArrayNode
			|| $parent instanceof ArrayItemNode
			// the variables of an unset, the items of a destructuring
			|| $parent instanceof NodeList || $parent instanceof SeparatedNodeList
		) {
			[$node, $parent] = [$parent, $parent->parent];
		}

		return match (true) {
			$parent instanceof Expression\AssignmentNode,
			$parent instanceof Expression\AssignmentByReferenceNode,
			$parent instanceof Expression\CombinedAssignmentNode => $parent->findSlotOf($node) === 'target',
			$parent instanceof Expression\PrefixOpNode,
			$parent instanceof Expression\PostfixOpNode,
			$parent instanceof Statement\UnsetNode => true,
			$parent instanceof ArgumentNode => $parent->ampersand !== null,
			default => false,
		};
	}


	/** Whether the two expressions are the same array written twice, which the call may therefore read once. */
	private static function repeats(ExpressionNode $one, ExpressionNode $other): bool
	{
		return $one->matches($other) && $other->isRepeatableRead();
	}


	private static function isInteger(ExpressionNode $expression, int $value): bool
	{
		return $expression instanceof IntegerNode && $expression->value === $value;
	}
}
