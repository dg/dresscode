<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{NodeRule, Risk, RuleContext, RuleGroup, RuleInfo, Stage};
use DressCode\Rules\{CodeWriter, GlobalCalls, NodeHelpers};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, Expression, ExpressionNode, NameNode};
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
#[RuleInfo(
	'dresscode/noManualListTests',
	Stage::Structure,
	description: 'Replaces a hand-written test for a list with `array_is_list()`',
	group: RuleGroup::Modernization,
	requires: ['php' => '>=8.1'],
)]
final class NoManualListTestsRule extends NodeRule
{
	public function getVisitedTypes(): array
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

		[$array, $call, $risky] = $this->describe($node->left, $node->right, $context)
			?? $this->describe($node->right, $node->left, $context)
			?? [null, null, false];
		if ($array === null || $call === null || $node->hasInnerComment()) {
			return;
		}

		assert($call->name instanceof NameNode);
		$uncertainty = GlobalCalls::findUncertainty($call, $context);
		if (!$context->report(
			$node,
			'The test for a list must be written with `array_is_list()`',
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
	private function describe(ExpressionNode $call, ExpressionNode $compared, RuleContext $context): ?array
	{
		$argument = self::readSingleArgument($call, 'array_values', $context);
		if ($argument !== null) {
			assert($call instanceof Expression\FunctionCallNode);
			return self::repeats($argument, $compared) ? [$compared, $call, false] : null;
		}

		$keys = self::readSingleArgument($call, 'array_keys', $context);
		$arguments = $compared instanceof Expression\FunctionCallNode
			&& GlobalCalls::findFunction($compared, ['range' => true], $context) !== null
			? self::readArguments($compared, $context)
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
			? self::readArguments($call, $context)
			: null;
		return $arguments !== null && count($arguments) === 1 ? $arguments[0] : null;
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
