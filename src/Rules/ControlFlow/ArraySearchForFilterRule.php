<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\Analyses\PhpSignatures;
use DressCode\{NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\{CodeWriter, GlobalCalls};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, ArrayNode, ArrowFunctionNode, BinaryOpNode, ClosureNode, EmptyNode, FunctionCallNode, UnaryOpNode};
use PhpSyntax\Nodes\{ExpressionNode, NameNode, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Scalar\{IntegerNode, NullNode, StringNode};
use PhpSyntax\Nodes\Statement\ReturnNode;
use function count, in_array;


/**
 * A question `array_filter()` answers by building an array only to look at it is asked with the function of
 * PHP 8.4 that asks it: whether any element passes is `array_any()` (`count(array_filter($a, $f)) > 0`,
 * `array_filter($a, $f) !== []`, `!empty(array_filter($a, $f))` and their negations), whether all pass is
 * `array_all()` (`count(array_filter($a, $f)) === count($a)`), the first that passes is `array_find()`
 * (`array_values(array_filter($a, $f))[0] ?? null`) and its key `array_find_key()`
 * (`array_key_first(array_filter($a, $f))`). Only a filter with a callback and no mode is taken.
 *
 * The new functions call the callback with the key as a second argument, and only until the answer is known.
 * A closure or an arrow function with one parameter, whose body does nothing but compute its answer, sees neither.
 * A function of PHP that takes no second argument answers the key with an error, so there the rule says nothing;
 * any other callback makes the fix risky, one taking a second argument getting the key in it, and one that counts
 * or logs being called fewer times.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.4'], decisions: ['upgrading.functions.arraySearchFunctions'], analyses: [PhpSignatures::class, NameResolver::class])]
final class ArraySearchForFilterRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [BinaryOpNode::class, EmptyNode::class, UnaryOpNode::class, FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$found = match (true) {
			$node instanceof UnaryOpNode => $node->operator->is('!') && $node->expression instanceof EmptyNode
				? ['array_any', $node->expression->expression, false]
				: null,
			$node instanceof EmptyNode => $node->parent instanceof UnaryOpNode && $node->parent->operator->is('!')
				? null // rewritten where the negation stands
				: ['array_any', $node->expression, true],
			$node instanceof BinaryOpNode => self::readComparison($node, $context),
			$node instanceof FunctionCallNode => GlobalCalls::findFunction($node, ['array_key_first' => true], $context) !== null
				? ['array_find_key', self::findSoleArgument($node), false]
				: null,
			default => null,
		};
		[$function, $filter, $negated] = $found ?? [null, null, false];
		$arguments = $filter instanceof FunctionCallNode ? self::readFilter($filter, $context) : null;
		if (
			$function === null
			|| $arguments === null
			|| !$node instanceof ExpressionNode
			|| $node->hasInnerComment()
			|| self::refusesKey($arguments[1], $context)
		) {
			return;
		}

		[$array, $callback] = $arguments;
		$because = self::findCallbackRisk($callback);
		$uncertainty = GlobalCalls::findUncertaintyOfRewrite($node, [$array, $callback], $context);
		if ($context->report(
			$node,
			"The search through `array_filter()` must be written with `$function()`.",
			risk: $because !== null ? Risk::BehaviorChanges : ($uncertainty === null ? null : Risk::NameUncertain),
			because: $because ?? $uncertainty,
		)) {
			$spelling = CodeWriter::spellFunction($function, $filter->name, $context);
			$node->replaceWithExpression((new Builder)->expression(($negated ? '!' : '') . "$spelling(\$array, \$callback)", array: $array, callback: $callback));
		}
	}


	/**
	 * What the comparison asks of a filter: the function, the filter and whether the answer is negated.
	 * @return ?array{string, ?ExpressionNode, bool}
	 */
	private static function readComparison(BinaryOpNode $node, RuleContext $context): ?array
	{
		$operator = $node->operator->text;
		if ($operator === '??') {
			$access = $node->left;
			return $node->right instanceof NullNode
				&& $access instanceof ArrayAccessNode
				&& $access->index instanceof IntegerNode && $access->index->text === '0'
				&& $access->expression instanceof FunctionCallNode
				&& GlobalCalls::findFunction($access->expression, ['array_values' => true], $context) !== null
					? ['array_find', self::findSoleArgument($access->expression), false]
					: null;
		}

		[$left, $right] = [$node->left, $node->right];
		if (!$left instanceof FunctionCallNode) { // 0 < count(...) reads as count(...) > 0
			[$left, $right, $operator] = [$right, $left, ['<' => '>', '>' => '<', '<=' => '>=', '>=' => '<='][$operator] ?? $operator];
		}

		$counted = self::readCounted($left, $context);
		$countedRight = self::readCounted($right, $context);
		$equality = in_array($operator, ['===', '==', '!==', '!='], true);
		$unequal = in_array($operator, ['!==', '!='], true);
		$value = $right instanceof IntegerNode ? $right->text : null;
		return match (true) {
			$counted !== null && in_array([$operator, $value], [['>', '0'], ['!==', '0'], ['!=', '0'], ['>=', '1']], true) => ['array_any', $counted, false],
			$counted !== null && in_array([$operator, $value], [['===', '0'], ['==', '0'], ['<', '1'], ['<=', '0']], true) => ['array_any', $counted, true],
			$equality && $counted instanceof FunctionCallNode && self::countsFiltered($counted, $right, $context) => ['array_all', $counted, $unequal],
			$equality && $countedRight instanceof FunctionCallNode && self::countsFiltered($countedRight, $left, $context)
				=> ['array_all', $countedRight, $unequal],
			$right instanceof ArrayNode && $right->items->isEmpty() && in_array($operator, ['!==', '!=', '===', '=='], true)
				=> ['array_any', $left, in_array($operator, ['===', '=='], true)],
			default => null,
		};
	}


	/** Whether the expression counts the array the filter filters, which makes the comparison ask whether all pass. */
	private static function countsFiltered(FunctionCallNode $filter, ExpressionNode $count, RuleContext $context): bool
	{
		$array = self::readFilter($filter, $context)[0] ?? null;
		$counted = self::readCounted($count, $context);
		return $array !== null && $counted !== null && $array->isRepeatableRead() && $array->matches($counted);
	}


	/** What the expression counts, the sole argument of a call of `count()`; null for any other expression. */
	private static function readCounted(ExpressionNode $node, RuleContext $context): ?ExpressionNode
	{
		return $node instanceof FunctionCallNode && GlobalCalls::findFunction($node, ['count' => true], $context) !== null
			? self::findSoleArgument($node)
			: null;
	}


	/**
	 * The array and the callback of a call of `array_filter()` given both and nothing else.
	 * @return ?array{ExpressionNode, ExpressionNode}
	 */
	private static function readFilter(FunctionCallNode $call, RuleContext $context): ?array
	{
		$values = $call->arguments->getPlainValues();
		return $values !== null
			&& count($values) === 2
			&& GlobalCalls::findFunction($call, ['array_filter' => true], $context) !== null
				? [$values[0], $values[1]]
				: null;
	}


	/** The value of the one plain argument of the call; null for a call with any other arguments. */
	private static function findSoleArgument(FunctionCallNode $call): ?ExpressionNode
	{
		$values = $call->arguments->getPlainValues();
		return $values !== null && count($values) === 1 ? $values[0] : null;
	}


	/**
	 * Whether the callback is a function of PHP taking no second argument, `'is_numeric'` or `is_array(...)`, which
	 * answers the key the new function passes with an error, so that the fix would be wrong.
	 */
	private static function refusesKey(ExpressionNode $callback, RuleContext $context): bool
	{
		$name = match (true) {
			$callback instanceof StringNode => preg_match('~^\\\\?\w+$~D', $callback->toValue()) === 1 ? ltrim($callback->toValue(), '\\') : null,
			$callback instanceof FunctionCallNode
			&& $callback->name instanceof NameNode
			&& $callback->arguments->items->count() === 1
			&& $callback->arguments->items->getItems()[0] instanceof VariadicPlaceholderNode
			&& $context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($callback)
				=> ltrim($context->getAnalysis(NameResolver::class)->resolveFunction($callback->name), '\\'),
			default => null,
		};
		$parameters = $name === null ? null : $context->getAnalysis(PhpSignatures::class)->findParameters($name);
		return $parameters !== null && count($parameters) < 2 && !array_any($parameters, fn($parameter) => $parameter->variadic);
	}


	/** What a callback may notice of the new function: the key it is given, or that it is called fewer times. */
	private static function findCallbackRisk(ExpressionNode $callback): ?string
	{
		$parameters = $callback instanceof ClosureNode || $callback instanceof ArrowFunctionNode ? $callback->parameters->getItems() : [];
		if (count($parameters) !== 1 || $parameters[0]->ellipsis !== null || $parameters[0]->ampersand !== null) {
			return 'the callback gets the key as a second argument';
		}

		$statements = $callback instanceof ClosureNode ? $callback->body->statements->getItems() : [];
		$body = match (true) {
			$callback instanceof ArrowFunctionNode => $callback->expression,
			count($statements) === 1 && $statements[0] instanceof ReturnNode => $statements[0]->expression,
			default => null,
		};
		return $body !== null && !$body->hasEffect()
			? null
			: 'the callback is called only until the answer is known';
	}
}
