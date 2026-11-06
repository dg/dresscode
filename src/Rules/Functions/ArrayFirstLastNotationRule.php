<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\Types;
use DressCode\{CodeWriter, Decision, Domain, GlobalCalls, NodeRule, RuleContext, RuleInfo, Stage, Tristate};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{Expression, ExpressionNode, NameNode};
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
 * The pointer is no risk where the call is the last read of a local variable (`NodeHelpers::isLastRead()`), and
 * the empty array none where the types say the array has an item; without the types, an array with an item is not
 * told from one that may be empty. A call of theirs whose value nothing takes is left alone, being there for the
 * pointer.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.5'], analyses: [Types::class, NameResolver::class])]
final class ArrayFirstLastNotationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'upgrading.functions.arrayFirstLast',
			Domain::state('required'),
			'`array_first()`, `array_last()` for `reset()`, `end()` read as an item',
		)];
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

		$pointerCall = $node instanceof Expression\FunctionCallNode;
		$found = $pointerCall
			? self::readPointerCall($node, $context)
			: self::readOffset($node, $context);
		if ($found === null || $node->hasInnerComment()) {
			return;
		}

		[$function, $array, $replaced] = $found;

		$uncertainty = GlobalCalls::findUncertaintyOfRewrite($node, [$array], $context);
		$element = $function === 'array_first' ? 'first' : 'last';
		$pointerMatters = $pointerCall
			&& !($array instanceof Expression\VariableNode && NodeHelpers::isLastRead($array, $node, $context));
		$emptyMatters = $pointerCall
			&& $context->findAnalysis(Types::class)?->isOfType($array, 'non-empty-array') !== Tristate::Yes;
		$because = match (true) {
			$pointerMatters && $emptyMatters => '`reset()` and `end()` move the pointer of the array and return false for an empty one',
			$pointerMatters => '`reset()` and `end()` move the pointer of the array',
			$emptyMatters => '`reset()` and `end()` return false for an empty array',
			default => null,
		};
		if (!$context->report(
			$node,
			"The $element item must be read with `$function()`.",
			risk: NodeHelpers::findRisk($because, $uncertainty),
			because: $because ?? $uncertainty,
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
		foreach (['array_key_first' => 'array_first', 'array_key_last' => 'array_last'] as $key => $function) {
			$keyed = NodeHelpers::findSingleArgument($access->index, $key, $context);
			if ($keyed !== null && NodeHelpers::isRepeatedRead($keyed, $access->expression)) {
				assert($access->index instanceof Expression\FunctionCallNode);
				return [$function, $access->expression, $access->index->name];
			}
		}

		// array_values($a)[0] and array_values($a)[count($a) - 1]
		$array = NodeHelpers::findSingleArgument($access->expression, 'array_values', $context);
		if ($array === null) {
			return null;
		}

		assert($access->expression instanceof Expression\FunctionCallNode);
		if (NodeHelpers::isInteger($access->index, 0)) {
			return ['array_first', $array, $access->expression->name];
		}

		return NodeHelpers::isRepeatedRead(NodeHelpers::findCountedArray($access->index, $context), $array)
			? ['array_last', $array, $access->expression->name]
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
}
