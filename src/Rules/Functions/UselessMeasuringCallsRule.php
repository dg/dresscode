<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, GlobalCalls, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate, Values};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, ExpressionNode, NameNode};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, FunctionCallNode, ParenthesizedNode, UnaryOpNode};
use PhpSyntax\Nodes\Scalar\IntegerNode;
use function count;


/**
 * Whether an array or a string is empty is `$a === []` or `$s === ''`, not what a function measures of it compared with
 * zero: `count($a) > 0` is `$a !== []`, `!sizeof($a)` is `$a === []`, `strlen($s) > 0` is `$s !== ''`. The comparisons
 * read are those that ask exactly that, with 0 or 1 on either side; `count($a) >= 0` asks nothing and stays.
 *
 * The types decide it, which is what the measure alone cannot: only a value that is an array or a string for sure is
 * compared, because `count()` counts a Countable object too, which is never `[]`, and throws on anything else, and
 * `strlen()` counts `null` as empty, an int as its digits and a Stringable object as its text, all of which `===` sees
 * differently.
 */
#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [Types::class, NameResolver::class])]
final class UselessMeasuringCallsRule extends NodeRule
{
	private const Count = 'hygiene.countEmptyTest';
	private const Strlen = 'hygiene.strlenEmptyTest';

	/** the function => the decision, the type it measures and the empty value of that type */
	private const Functions = [
		'count' => [self::Count, 'array', '[]'],
		'sizeof' => [self::Count, 'array', '[]'],
		'strlen' => [self::Strlen, 'string', "''"],
		'mb_strlen' => [self::Strlen, 'string', "''"],
	];

	/** @var array<lowercase-string, array{string, string, string}>  those whose decision is not kept */
	private array $functions = self::Functions;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Count, Domain::state('forbidden'), '`count($a) === 0` is `$a === []`'),
			new Decision(self::Strlen, Domain::state('forbidden'), '`strlen($s) === 0` is `$s === \'\'`'),
		];
	}


	public function configure(Values $values): void
	{
		$this->functions = array_filter(self::Functions, fn(array $function) => !$values->isKept($function[0]));
	}


	public function getVisitedNodes(): array
	{
		return [BinaryOpNode::class, UnaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BinaryOpNode && !$node instanceof UnaryOpNode) {
			return;
		}

		[$call, $empty] = $node instanceof BinaryOpNode
			? self::readComparison($node)
			: ($node->operator->is('!') ? [self::unwrap($node->expression), true] : [null, null]);
		if (
			!$call instanceof FunctionCallNode
			|| $empty === null
			|| !$call->name instanceof NameNode
			|| count($call->arguments->items) !== 1
			|| !($argument = $call->arguments->items->getItems()[0]) instanceof ArgumentNode
			|| $argument->name !== null
			|| $argument->ellipsis !== null
			|| ($function = GlobalCalls::findFunction($call, $this->functions, $context)) === null
		) {
			return;
		}

		[$decision, $type, $emptyValue] = $this->functions[$function];
		$value = $argument->value;
		if ($context->getAnalysis(Types::class)->isOfType($value, $type) !== Tristate::Yes) {
			return;
		}

		$comparison = ($empty ? '===' : '!==') . " $emptyValue";
		$uncertainty = GlobalCalls::findUncertainty($call, $context);
		$fixable = !$node->getFirstToken()->hasCommentUpTo($value->getFirstToken())
			&& !$value->getLastToken()->hasCommentUpTo($node->getLastToken());
		if (!$context->report(
			$node,
			"Useless `$function()`, because `$comparison` says the same.",
			decision: $decision,
			fixable: $fixable,
			risk: $uncertainty === null ? null : Risk::NameUncertain,
			because: $uncertainty,
		)) {
			return;
		}

		// an equality binds more loosely than the comparison or the negation it takes the place of
		$node->replaceWithExpression((new Builder)->expression("\$value $comparison", value: $value));
	}


	/**
	 * The call compared and whether the comparison asks for the empty value, true, or for a value that is not empty,
	 * false; nulls for a comparison that asks neither.
	 * @return array{?ExpressionNode, ?bool}
	 */
	private static function readComparison(BinaryOpNode $comparison): array
	{
		$operator = $comparison->operator->text;
		[$call, $number] = [self::unwrap($comparison->left), $comparison->right];
		if ($call instanceof IntegerNode) { // written in the other order, so the ordering reads mirrored
			[$call, $number] = [self::unwrap($comparison->right), $call];
			$operator = strtr($operator, ['<' => '>', '>' => '<']);
		}

		if (!$number instanceof IntegerNode) {
			return [null, null];
		}

		$empty = match ([$operator, $number->toValue()]) {
			['===', 0], ['==', 0], ['<=', 0], ['<', 1] => true,
			['!==', 0], ['!=', 0], ['>', 0], ['>=', 1] => false,
			default => null,
		};
		return [$call, $empty];
	}


	private static function unwrap(ExpressionNode $expression): ExpressionNode
	{
		return $expression instanceof ParenthesizedNode ? $expression->expression : $expression;
	}
}
