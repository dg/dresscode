<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\{CodeWriter, GlobalCalls};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{Expression, ExpressionNode, NameNode};
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
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.5'], analyses: [NameResolver::class])]
final class ArrayFirstLastNotationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.functions.arrayFirstLast', Domain::adopted(), '`array_first()`, `array_last()` for `reset()`, `end()` read as an item')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\FunctionCallNode::class, Expression\ArrayAccessNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ExpressionNode) {
			return;
		}

		$risky = $node instanceof Expression\FunctionCallNode;
		$found = $risky
			? self::readPointerCall($node, $context)
			: self::readOffset($node, $context);
		if ($found === null || $node->hasInnerComment()) {
			return;
		}

		[$function, $array, $replaced] = $found;

		$uncertainty = GlobalCalls::findUncertaintyOfRewrite($node, [$array], $context);
		$element = $function === 'array_first' ? 'first' : 'last';
		if (!$context->report(
			$node,
			"The $element item must be read with `$function()`.",
			risk: $risky ? Risk::BehaviorChanges : ($uncertainty === null ? null : Risk::NameUncertain),
			because: $risky ? '`reset()` and `end()` move the pointer of the array and return false for an empty one' : $uncertainty,
		)) {
			return;
		}

		$spelling = CodeWriter::spellFunction($function, $replaced, $context);
		$node->replaceWith((new Builder)->call($spelling, [$array]));
	}


	/**
	 * The array a call of `reset()` or `end()` reads the edge element of, where something takes the value, and the
	 * name of the call.
	 * @return ?array{string, ExpressionNode, NameNode|ExpressionNode}
	 */
	private static function readPointerCall(Expression\FunctionCallNode $call, RuleContext $context): ?array
	{
		$function = match (GlobalCalls::findFunction($call, ['reset' => true, 'end' => true], $context)) {
			'reset' => 'array_first',
			'end' => 'array_last',
			default => null,
		};
		$arguments = $function === null ? null : $call->arguments->getPlainValues();
		return $arguments === null || count($arguments) !== 1 || $call->parent instanceof ExpressionStatementNode
			? null
			: [$function, $arguments[0], $call->name];
	}


	/**
	 * The array an offset access reads the edge element of, by the key the edge has or by the position it
	 * takes among the values, and the name of the call that is replaced.
	 * @return ?array{string, ExpressionNode, NameNode|ExpressionNode}
	 */
	private static function readOffset(ExpressionNode $access, RuleContext $context): ?array
	{
		// a call gives a value, never the place the code writes to, unsets or asks `isset()` about
		if (
			!$access instanceof Expression\ArrayAccessNode
			|| $access->index === null
			|| (!$access->index instanceof Expression\FunctionCallNode && !$access->expression instanceof Expression\FunctionCallNode)
			|| $access->isWritten()
			|| self::isIssetOperand($access)
		) {
			return null;
		}

		// $a[array_key_first($a)]
		$keyed = self::readSingleArgument($access->index, ['array_key_first' => 'array_first', 'array_key_last' => 'array_last'], $context);
		if ($keyed !== null && self::repeats($keyed[1], $access->expression)) {
			return [$keyed[0], $access->expression, $keyed[2]];
		}

		// array_values($a)[0] and array_values($a)[count($a) - 1]
		$values = self::readSingleArgument($access->expression, ['array_values' => 'array_values'], $context);
		if ($values === null) {
			return null;
		}

		$array = $values[1];
		if (self::isInteger($access->index, 0)) {
			return ['array_first', $array, $values[2]];
		}

		$counted = $access->index instanceof Expression\BinaryOpNode
			&& $access->index->operator->is('-')
			&& self::isInteger($access->index->right, 1)
				? self::readSingleArgument($access->index->left, ['count' => 'count'], $context)
				: null;
		return $counted !== null && self::repeats($counted[1], $array)
			? ['array_last', $array, $values[2]]
			: null;
	}


	private static function isIssetOperand(ExpressionNode $expression): bool
	{
		$node = $expression->parent;
		while ($node instanceof Expression\ParenthesizedNode) {
			$node = $node->parent;
		}

		return $node?->parent instanceof Expression\IssetNode;
	}


	/**
	 * The function the call names, mapped by the given table, its only argument and the name of the call.
	 * @param  array<lowercase-string, string>  $functions
	 * @return ?array{string, ExpressionNode, NameNode|ExpressionNode}
	 */
	private static function readSingleArgument(ExpressionNode $call, array $functions, RuleContext $context): ?array
	{
		$name = $call instanceof Expression\FunctionCallNode ? GlobalCalls::findFunction($call, $functions, $context) : null;
		$arguments = $name === null ? null : $call->arguments->getPlainValues();
		return $arguments === null || count($arguments) !== 1 ? null : [$functions[$name], $arguments[0], $call->name];
	}


	/** Whether the two expressions are the same array written twice, which the call may therefore read once. */
	private static function repeats(ExpressionNode $one, ExpressionNode $other): bool
	{
		return $one->matches($other) && $other->isRepeatableRead();
	}


	private static function isInteger(ExpressionNode $expression, int $value): bool
	{
		return $expression instanceof IntegerNode && $expression->toValue() === $value;
	}
}
