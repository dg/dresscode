<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\{CodeWriter, GlobalCalls, NodeHelpers};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{Expression, ExpressionNode};
use PhpSyntax\Nodes\Scalar\IntegerNode;
use function count;


/**
 * Whether an array is a list is what `array_is_list()` of PHP 8.1 answers, so the two ways of asking it by
 * hand give way to it: `$a === array_values($a)`, which holds only for keys counting from zero in order, and
 * `array_keys($a) === range(0, count($a) - 1)`, which spells the same keys out. The array must be the same
 * expression on both sides and free of side effects, because the call reads it once.
 *
 * The form with `range()` is risky, and for one array: `range(0, -1)` is not empty but counts backwards, so
 * for an empty array the comparison says it is no list, while `array_is_list([])` says it is.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.1'], analyses: [NameResolver::class])]
final class NoManualListTestsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.functions.array_is_list', Domain::adopted(), '`array_is_list()` for a hand-written test of a list')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\BinaryOpNode
			|| !$node->operator->is([Token::IsIdentical, Token::IsNotIdentical])
			|| (!$node->left instanceof Expression\FunctionCallNode && !$node->right instanceof Expression\FunctionCallNode)
		) {
			return;
		}

		[$array, $call, $risky] = self::readTest($node->left, $node->right, $context)
			?? self::readTest($node->right, $node->left, $context)
			?? [null, null, false];
		if ($array === null || $call === null || $node->hasInnerComment()) {
			return;
		}

		$uncertainty = GlobalCalls::findUncertaintyOfRewrite($node, [$array], $context);
		if (!$context->report(
			$node,
			'The test for a list must be written with `array_is_list()`.',
			risk: $risky ? Risk::BehaviorChanges : ($uncertainty === null ? null : Risk::NameUncertain),
			because: $risky ? '`array_is_list()` takes an empty array for a list, the comparison with `range()` does not' : $uncertainty,
		)) {
			return;
		}

		$positive = $node->operator->is(Token::IsIdentical);
		$spelling = CodeWriter::spellFunction('array_is_list', $call->name, $context);
		$test = (new Builder)->call($spelling, [$array]);
		$node->replaceWith($positive ? $test : NodeHelpers::negate($test));
	}


	/**
	 * The array the two sides ask about together, the call that named it, and whether the answer may differ
	 * from the one `array_is_list()` gives; null where they ask about something else.
	 * @return ?array{ExpressionNode, Expression\FunctionCallNode, bool}
	 */
	private static function readTest(ExpressionNode $call, ExpressionNode $compared, RuleContext $context): ?array
	{
		$argument = self::readSingleArgument($call, 'array_values', $context);
		if ($argument !== null) {
			assert($call instanceof Expression\FunctionCallNode);
			return self::repeats($argument, $compared) ? [$compared, $call, false] : null;
		}

		$keys = self::readSingleArgument($call, 'array_keys', $context);
		$arguments = $compared instanceof Expression\FunctionCallNode
			&& GlobalCalls::findFunction($compared, ['range' => true], $context) !== null
				? $compared->arguments->getPlainValues()
				: null;
		if ($keys === null || $arguments === null || count($arguments) !== 2 || !self::isInteger($arguments[0], 0)) {
			return null;
		}

		assert($call instanceof Expression\FunctionCallNode);
		$counted = $arguments[1] instanceof Expression\BinaryOpNode
			&& $arguments[1]->operator->is('-')
			&& self::isInteger($arguments[1]->right, 1)
				? self::readSingleArgument($arguments[1]->left, 'count', $context)
				: null;
		return $counted !== null && self::repeats($counted, $keys) ? [$keys, $call, true] : null;
	}


	/**
	 * The only argument of a call of the named global function, null for any other expression.
	 * @param lowercase-string $function
	 */
	private static function readSingleArgument(
		ExpressionNode $call,
		string $function,
		RuleContext $context,
	): ?ExpressionNode
	{
		$arguments = $call instanceof Expression\FunctionCallNode && GlobalCalls::findFunction($call, [$function => true], $context) !== null
			? $call->arguments->getPlainValues()
			: null;
		return $arguments !== null && count($arguments) === 1 ? $arguments[0] : null;
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
