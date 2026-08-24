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
use function count;


/**
 * `is_object($v) ? get_class($v) : gettype($v)` asks what a value is in three calls, which is what
 * `get_debug_type()` of PHP 8.0 answers in one; the negated ternary is read the same way.
 *
 * Every fix is risky, because the two do not spell the same answer: `gettype()` says `integer`, `double`,
 * `boolean` and `NULL` where `get_debug_type()` says `int`, `float`, `bool` and `null`, and a message or
 * a comparison built on those words says something else afterwards. Without the types, a value that is
 * never one of those is not told from one that may be.
 */
#[RuleInfo(
	'dresscode/get-debug-type-for-type-ternary',
	Stage::Structure,
	description: 'Replaces a ternary asking whether a value is an object with get_debug_type()',
	group: Group::Modernization,
	requires: ['php' => '>=8.0'],
	risky: true,
)]
final class GetDebugTypeForTypeTernaryRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Expression\TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\TernaryNode || $node->then === null || $node->hasComment()) {
			return;
		}

		$condition = $node->condition;
		$negated = $condition instanceof Expression\UnaryOpNode && $condition->operator->is('!');
		$test = $negated ? $condition->expression : $condition;
		[$class, $type] = $negated ? [$node->else, $node->then] : [$node->then, $node->else];
		$subject = self::readSingleArgument($test, 'is_object', $context);
		if (
			$subject === null
			|| !$subject->isRepeatableRead()
			|| ($named = self::readSingleArgument($class, 'get_class', $context)) === null
			|| !$named->matches($subject)
			|| ($typed = self::readSingleArgument($type, 'gettype', $context)) === null
			|| !$typed->matches($subject)
		) {
			return;
		}

		assert($test instanceof Expression\FunctionCallNode && $test->name instanceof NameNode);
		$uncertainty = NodeHelpers::findUncertainty($test, $context);
		if (!$context->report($node, 'The type of the value must be asked for with get_debug_type()' . $uncertainty)) {
			return;
		}

		$spelling = NodeHelpers::spellGlobalFunction('get_debug_type', $test->name, $context);
		$node->replaceWith(Expression\FunctionCallNode::of(NameNode::fromText($spelling), ArgumentListNode::of($subject->withoutEdgeTrivia())));
	}


	/** The only argument of a call of the named global function, null for any other expression. */
	private static function readSingleArgument(
		?ExpressionNode $call,
		string $function,
		RuleContext $context,
	): ?ExpressionNode
	{
		$argument = $call instanceof Expression\FunctionCallNode ? $call->arguments->items->getItems()[0] ?? null : null;
		return $call instanceof Expression\FunctionCallNode
			&& $call->name instanceof NameNode
			&& count($call->arguments->items) === 1
			&& $argument instanceof ArgumentNode
			&& !$argument->name && !$argument->ampersand && !$argument->ellipsis
			&& $context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call, $function)
			? $argument->value
			: null;
	}
}
