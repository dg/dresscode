<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use DressCode\Rules\{GlobalCalls, NodeHelpers};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, ArrayItemNode, ExpressionNode};
use PhpSyntax\Nodes\Expression\{ArrayNode, FunctionCallNode};
use PhpSyntax\Nodes\Scalar\StringNode;


/**
 * Arrays merged by `array_merge()` are written as an array spreading them, `[...$defaults, ...$options]`, which since
 * PHP 8.1 keeps the string keys as the function does, the later one winning, and numbers the others anew. The two part
 * where an argument is no array: the function refuses it with a TypeError, while spreading takes an object that is
 * `Traversable` too. Without the types, an argument that is not an array literal is not told from such an object, so
 * the fix is risky there; a call with an argument the types know is no array is left as it is.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.1'], analyses: [Types::class, NameResolver::class])]
final class SpreadForArrayMergeRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.syntax.spreadForArrayMerge', Domain::adopted(), '`[...$a, ...$b]` for `array_merge($a, $b)`')];
	}


	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof FunctionCallNode
			|| GlobalCalls::findFunction($node, ['array_merge' => true], $context) === null
			|| $node->hasInnerComment()
			|| ($values = $node->arguments->getPlainValues()) === null
		) {
			return;
		}

		$types = $context->findAnalysis(Types::class);
		$arrays = array_map(
			fn(ExpressionNode $value) => $value instanceof ArrayNode ? Tristate::Yes : $types?->isOfType($value, 'array') ?? Tristate::Maybe,
			$values,
		);
		if (in_array(Tristate::No, $arrays, true)) {
			return; // the call refuses such an argument, which the spread may take
		}

		$certain = array_all($arrays, fn(Tristate $array) => $array === Tristate::Yes);
		$uncertainName = GlobalCalls::findUncertainty($node, $context);
		if (!$context->report(
			$node,
			'The `array_merge()` call must be written as an array spreading its arguments.',
			risk: match (true) {
				!$certain => Risk::TypeUnknown,
				$uncertainName !== null => Risk::NameUncertain,
				default => null,
			},
			because: $certain ? $uncertainName : 'an argument may be an object, which the spread takes and `array_merge()` refuses',
		)) {
			return;
		}

		$parts = $placeholders = [];
		foreach ($values as $i => $value) {
			$items = $value instanceof ArrayNode ? $value->items->getItems() : null;
			if ($items !== null && array_all($items, self::isMergedAlike(...))) {
				array_push($parts, ...array_map(fn(Node $item) => $item->text, $items));
			} else {
				$parts[] = "...\$a$i";
				$placeholders["a$i"] = $value;
			}
		}

		if ($parts !== [] && self::isWrittenOverLines($node)) {
			$style = $context->style;
			$indentation = $node->getFirstToken()->getLineIndentation();
			$separator = $style->lineEnding . $indentation . $style->indent;
			$text = '[' . $separator . implode(',' . $separator, $parts) . ',' . $style->lineEnding . $indentation . ']';
		} else {
			$text = '[' . implode(', ', $parts) . ']';
		}

		$node->replaceWith((new Builder)->expression($text, ...$placeholders));
	}


	/**
	 * Whether the author wrote the call over several lines, its arguments or one of the merged arrays, which the array written
	 * for it keeps with an item on each line.
	 */
	private static function isWrittenOverLines(FunctionCallNode $call): bool
	{
		$arguments = $call->arguments;
		return NodeHelpers::isMultiline($arguments->openParen, $arguments->items->getItems(), $arguments->closeParen)
			|| array_any(
				$arguments->items->getItems(),
				fn(Node $argument) => $argument instanceof ArgumentNode
					&& $argument->value instanceof ArrayNode
					&& NodeHelpers::isMultiline($argument->value->openDelimiter, $argument->value->items->getItems(), $argument->value->closeDelimiter),
			);
	}


	/**
	 * Whether the item of an array literal stands the same written straight into the merged array: one without a key
	 * is appended either way and a string key wins over an earlier one either way, while `array_merge()` numbers an
	 * integer key anew where the literal would keep it.
	 */
	private static function isMergedAlike(Node $item): bool
	{
		return $item instanceof ArrayItemNode
			&& $item->ampersand === null
			&& ($item->key === null || ($item->key instanceof StringNode && preg_match('~^(0|-?[1-9]\d*)$~D', $item->key->toValue()) !== 1)); // '1' is the key 1
	}
}
