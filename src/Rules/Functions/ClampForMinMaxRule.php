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
use PhpSyntax\Nodes\{Expression, ExpressionNode};
use function count, is_float, is_int;


/**
 * `max(0, min(100, $value))` holds a value between two bounds, which is what `clamp()` of PHP 8.6 is for and
 * says. Which of the two arguments of the inner call is the bound the code must show by writing it out as
 * a value; where both or neither are written out, the pair says nothing about which is which and stays.
 *
 * The fix is risky unless both bounds are written out as numbers, the minimum not above the maximum:
 * `clamp()` refuses a minimum above its maximum with an error, while the pair of calls answers such bounds
 * with one of them.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.6'], analyses: [NameResolver::class])]
final class ClampForMinMaxRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.functions.clamp', Domain::adopted(), '`clamp($x, $lo, $hi)` for `max($lo, min($x, $hi))`')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\FunctionCallNode || $node->hasInnerComment()) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$inner = match (true) {
			$resolver->isGlobalFunctionCall($node, 'max') => 'min',
			$resolver->isGlobalFunctionCall($node, 'min') => 'max',
			default => null,
		};
		$outer = $inner === null ? null : $node->arguments->getPlainValues();
		if ($outer === null || count($outer) !== 2) {
			return;
		}

		$isInner = fn(ExpressionNode $argument) => $argument instanceof Expression\FunctionCallNode && $resolver->isGlobalFunctionCall($argument, $inner);
		[$call, $outerBound] = $isInner($outer[0]) ? [$outer[0], $outer[1]] : [$outer[1], $outer[0]];
		$arguments = $call instanceof Expression\FunctionCallNode && $isInner($call)
			? $call->arguments->getPlainValues()
			: null;
		if ($arguments === null || count($arguments) !== 2) {
			return;
		}

		if ($arguments[0]->hasValue() === $arguments[1]->hasValue()) {
			return;
		}

		[$value, $innerBound] = $arguments[0]->hasValue()
			? [$arguments[1], $arguments[0]]
			: [$arguments[0], $arguments[1]];
		[$min, $max] = $inner === 'min' ? [$outerBound, $innerBound] : [$innerBound, $outerBound];
		$uncertainty = GlobalCalls::findUncertaintyOfRewrite($node, [$value, $min, $max], $context);
		$ordered = self::isOrdered($min, $max);
		$reordered = $value->hasEffect() && $outerBound->hasEffect();
		if (!$context->report(
			$node,
			'The value held between bounds must be written with `clamp()`.',
			risk: $ordered ? ($uncertainty === null ? null : Risk::NameUncertain) : Risk::BehaviorChanges,
			because: match (true) {
				$ordered => $uncertainty,
				$reordered => '`clamp()` reads the value first and refuses a minimum above its maximum',
				default => '`clamp()` refuses a minimum above its maximum with an error',
			},
		)) {
			return;
		}

		$spelling = CodeWriter::spellFunction('clamp', $node->name, $context);
		$node->replaceWith((new Builder)->call($spelling, [$value, $min, $max]));
	}


	/** Whether both bounds are written out and the smaller one is the minimum, which is what `clamp()` takes. */
	private static function isOrdered(ExpressionNode $min, ExpressionNode $max): bool
	{
		if (!$min->hasValue() || !$max->hasValue()) {
			return false;
		}

		[$low, $high] = [$min->toValue(), $max->toValue()];
		return (is_int($low) || is_float($low)) && (is_int($high) || is_float($high)) && $low <= $high;
	}
}
