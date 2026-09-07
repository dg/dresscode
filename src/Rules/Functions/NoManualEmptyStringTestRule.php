<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\Types;
use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, ExpressionNode, NameNode};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, FunctionCallNode, ParenthesizedNode, UnaryOpNode};
use PhpSyntax\Nodes\Scalar\{IntegerNode, StringNode};
use function count, in_array;


/**
 * Whether a string is empty is `$s === ''`, not the length of it compared with zero: `strlen($s) > 0` is
 * `$s !== ''`, `!mb_strlen($s)` is `$s === ''`. The comparisons read are those that ask exactly that, with 0 or 1 on
 * either side; `strlen($s) >= 0` asks nothing and stays.
 *
 * The types decide it, which is what the length alone cannot: only a value that is a string for sure is compared
 * with `''`, because `strlen()` counts `null` as empty, an int as its digits and a Stringable object as its text,
 * all of which `===` sees differently.
 */
#[RuleInfo(
	'dresscode/no-manual-empty-string-test',
	Stage::Structure,
	description: 'Tests whether a string is empty by comparing it with the empty string',
	group: Group::Cleanup,
	requiresTypes: true,
)]
final class NoManualEmptyStringTestRule extends NodeRule
{
	public function getVisitedTypes(): array
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
		$resolver = $context->getAnalysis(NameResolver::class);
		if (
			!$call instanceof FunctionCallNode
			|| $empty === null
			|| !$call->name instanceof NameNode
			|| !$resolver->isGlobalFunctionCall($call)
			|| !in_array($function = strtolower($resolver->resolveFunction($call->name)), ['strlen', 'mb_strlen'], true)
			|| count($call->arguments->items) !== 1
			|| !($argument = $call->arguments->items->getItems()[0]) instanceof ArgumentNode
			|| $argument->name !== null
			|| $argument->ellipsis !== null
			|| $context->getAnalysis(Types::class)->getType($argument->value)?->isString()->yes() !== true
		) {
			return;
		}

		$value = $argument->value;
		$uncertainty = NodeHelpers::findUncertainty($call, $context);
		$fixable = $node->getFirstToken()?->hasCommentUpTo($value->getFirstToken() ?? $call->arguments->closeParen) === false
			&& $value->getLastToken()?->hasCommentUpTo($node->getLastToken() ?? $call->arguments->closeParen) === false;
		if (!$context->report(
			$node,
			'The empty string must be tested with ' . ($empty ? "=== ''" : "!== ''") . ", not through $function()" . $uncertainty,
			fixable: $fixable,
			risky: $fixable && $uncertainty !== null,
		)) {
			return;
		}

		// an equality binds more loosely than the comparison or the negation it takes the place of
		$node->replaceWithExpression(BinaryOpNode::of($value->withoutEdgeTrivia(), $empty ? '===' : '!==', StringNode::fromValue('')));
	}


	/**
	 * The call compared and whether the comparison asks for the empty string, true, or for a string that is not empty,
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

		$empty = match ([$operator, $number->value]) {
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
