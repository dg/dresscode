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
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, Expression, ExpressionNode, NameNode};
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
#[RuleInfo(
	'dresscode/clamp-for-min-max',
	Stage::Structure,
	description: 'Replaces a max() around a min() holding a value between bounds with clamp()',
	group: Group::Modernization,
	requires: ['php' => '>=8.6'],
)]
final class ClampForMinMaxRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\FunctionCallNode || $node->hasComment()) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$inner = match (true) {
			$resolver->isGlobalFunctionCall($node, 'max') => 'min',
			$resolver->isGlobalFunctionCall($node, 'min') => 'max',
			default => null,
		};
		$outer = $inner === null ? null : self::readArguments($node);
		if ($outer === null || count($outer) !== 2) {
			return;
		}

		[$call, $outerBound] = $outer[0] instanceof Expression\FunctionCallNode
			? [$outer[0], $outer[1]]
			: [$outer[1], $outer[0]];
		$arguments = $call instanceof Expression\FunctionCallNode && $resolver->isGlobalFunctionCall($call, $inner)
			? self::readArguments($call)
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
		assert($node->name instanceof NameNode);
		$uncertainty = NodeHelpers::findUncertainty($node, $context);
		if (!$context->report($node, 'The value held between bounds must be written with clamp()' . $uncertainty, risky: !self::isOrdered($min, $max) || $uncertainty !== null)) {
			return;
		}

		$spelling = NodeHelpers::spellGlobalFunction('clamp', $node->name, $context);
		$arguments = ArgumentListNode::of($value->withoutEdgeTrivia(), $min->withoutEdgeTrivia(), $max->withoutEdgeTrivia());
		$node->replaceWith(Expression\FunctionCallNode::of(NameNode::fromText($spelling), $arguments));
	}


	/** Whether both bounds are written out and the smaller one is the minimum, which is what clamp() takes. */
	private static function isOrdered(ExpressionNode $min, ExpressionNode $max): bool
	{
		if (!$min->hasValue() || !$max->hasValue()) {
			return false;
		}

		[$low, $high] = [$min->toValue(), $max->toValue()];
		return (is_int($low) || is_float($low)) && (is_int($high) || is_float($high)) && $low <= $high;
	}


	/**
	 * The values of the arguments of the call, null where it names an argument, passes one by reference
	 * or unpacks one, which is no plain pair of values.
	 * @return ?list<ExpressionNode>
	 */
	private static function readArguments(Expression\FunctionCallNode $call): ?array
	{
		$values = [];
		foreach ($call->arguments->items as $argument) {
			if (!$argument instanceof ArgumentNode || $argument->name || $argument->ampersand || $argument->ellipsis) {
				return null;
			}

			$values[] = $argument->value;
		}

		return $values;
	}
}
