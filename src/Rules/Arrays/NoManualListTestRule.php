<?php declare(strict_types=1);

namespace DressCode\Rules\Arrays;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentListNode;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
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
	'dresscode/no-manual-list-test',
	Stage::Structure,
	description: 'Replaces a hand-written test for a list with array_is_list()',
	group: Group::Modernization,
	requires: ['php' => '>=8.1'],
)]
final class NoManualListTestRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Expression\BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\BinaryOpNode
			|| !$node->operator->is(TokenKind::IsIdentical, TokenKind::IsNotIdentical)
			|| $node->hasComment()
		) {
			return;
		}

		[$array, $call, $risky] = $this->describe($node->left, $node->right, $context)
			?? $this->describe($node->right, $node->left, $context)
			?? [null, null, false];
		if ($array === null || $call === null) {
			return;
		}

		assert($call->name instanceof NameNode);
		$uncertainty = NodeHelpers::findUncertainty($call, $context);
		if (!$context->report($node, 'The test for a list must be written with array_is_list()' . $uncertainty, risky: $risky || $uncertainty !== null)) {
			return;
		}

		$positive = $node->operator->is(TokenKind::IsIdentical);
		$spelling = NodeHelpers::spellGlobalFunction('array_is_list', $call->name, $context);
		$test = Expression\FunctionCallNode::of(NameNode::fromText($spelling), ArgumentListNode::of($array->withoutEdgeTrivia()));
		$node->replaceWith($positive ? $test : NodeHelpers::negate($test));
	}


	/**
	 * The array the two sides ask about together, the call that named it, and whether the answer may differ
	 * from the one array_is_list() gives; null where they ask about something else.
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
			&& $context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($compared, 'range')
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


	/** The only argument of a call of the named global function, null for any other expression. */
	private static function readSingleArgument(
		ExpressionNode $call,
		string $function,
		RuleContext $context,
	): ?ExpressionNode
	{
		$arguments = self::readArguments($call, $context);
		return $arguments !== null
			&& count($arguments) === 1
			&& $context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call, $function)
			? $arguments[0]
			: null;
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
