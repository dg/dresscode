<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\{Lexer, Node, Token, Trivia};
use PhpSyntax\Nodes\{ArrayItemNode, DestructuringNode};
use PhpSyntax\Nodes\Expression\ArrayNode;


/**
 * The syntax of an array and of a destructuring: the short one, `[1, 2]` and `[$a, $b] = ...`, or the long one,
 * `array(1, 2)` and `list($a, $b) = ...`. A comment between the keyword and the parenthesis keeps the long syntax,
 * having nowhere to go, and so does a silenced destructuring; destructurings nested in one another are written in one
 * syntax or all keep theirs, PHP refusing to mix the two.
 */
#[RuleInfo(Stage::Structure)]
final class ArraySyntaxRule extends NodeRule
{
	private const Array = 'literals.arraySyntax.array';
	private const List = 'literals.arraySyntax.list';

	/** the syntax of an array, `short` or `long`, null where it is kept */
	private ?string $array = null;

	/** the syntax of a destructuring, `short` or `long`, null where it is kept */
	private ?string $list = null;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Array, new Words([
				'short' => '`[1, 2]`, never `array(1, 2)`',
				'long' => '`array(1, 2)`, never `[1, 2]`',
			]), 'The syntax of an array'),
			new Decision(self::List, new Words([
				'short' => '`[$a, $b] =`, never `list($a, $b) =`',
				'long' => '`list($a, $b) =`, never `[$a, $b] =`',
			]), 'The syntax of a destructuring'),
		];
	}


	public function configure(Values $values): void
	{
		$this->array = $values->find(self::Array)?->getWord();
		$this->list = $values->find(self::List)?->getWord();
	}


	public function getVisitedNodes(): array
	{
		return [
			...($this->array !== null ? [ArrayNode::class] : []),
			...($this->list !== null ? [DestructuringNode::class] : []),
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof ArrayNode && $this->array !== null) {
			$keyword = $node->arrayKeyword;
			if ($this->array === 'short'
				? $keyword !== null
					&& !$keyword->hasCommentUpTo($node->openDelimiter)
					&& $context->report($keyword, 'The array must be written `[…]` instead of `array(…)`.', decision: self::Array)
				: $keyword === null
					&& $context->report(
						$node->openDelimiter,
						'The array must be written `array(…)` instead of `[…]`.',
						decision: self::Array,
					)
			) {
				self::rewrite($node, $this->array, 'array');
			}

		} elseif ($node instanceof DestructuringNode && $this->list !== null && !$node->parent instanceof ArrayItemNode) {
			$nest = self::collectNest($node, $this->list);
			foreach ($nest as $list) {
				$at = $list->listKeyword ?? $list->openDelimiter;
				if (
					($list->listKeyword?->hasCommentUpTo($list->openDelimiter) ?? false)
					|| $context->isSilenced($at, decision: self::List)
				) {
					return;
				}
			}

			foreach ($nest as $list) {
				if ($context->report(
					$list->listKeyword ?? $list->openDelimiter,
					$this->list === 'short'
						? 'The destructuring must be written `[…]` instead of `list(…)`.'
						: 'The destructuring must be written `list(…)` instead of `[…]`.',
					decision: self::List,
				)) {
					self::rewrite($list, $this->list, 'list');
				}
			}
		}
	}


	/**
	 * The destructurings of the nest the node opens that are written in the other syntax.
	 * @return list<DestructuringNode>
	 */
	private static function collectNest(DestructuringNode $node, string $syntax): array
	{
		$nest = ($node->listKeyword === null) === ($syntax === 'long') ? [$node] : [];
		foreach ($node->items as $item) {
			if ($item instanceof ArrayItemNode && $item->value instanceof DestructuringNode) {
				array_push($nest, ...self::collectNest($item->value, $syntax));
			}
		}

		return $nest;
	}


	private static function rewrite(ArrayNode|DestructuringNode $node, string $syntax, string $word): void
	{
		$old = $node->openDelimiter;
		if ($syntax === 'short') {
			$keyword = $node instanceof ArrayNode ? $node->arrayKeyword : $node->listKeyword;
			$open = Token::fromText('[')->setLeadingTrivia($keyword->leadingTrivia ?? []);
			$keyword = null;
		} else {
			$keyword = Token::fromText($word)->setLeadingTrivia($old->leadingTrivia);
			$open = Token::fromText('(');
		}

		if ($node instanceof ArrayNode) {
			$node->arrayKeyword = $keyword;
		} else {
			$node->listKeyword = $keyword;
		}

		$node->openDelimiter = $open->setTrailingTrivia($old->trailingTrivia);
		$node->closeDelimiter->replaceWith(Token::fromText($syntax === 'short' ? ']' : ')'));

		// `return[1]` written long must not become one word
		$previous = $keyword?->getPrevious();
		if ($previous !== null && $keyword->leadingTrivia === [] && $previous->trailingTrivia === []
			&& !new Lexer()->canAdjoin($previous->text, $keyword->text)
		) {
			$previous->setTrailingTrivia([Trivia::fromText(' ')]);
		}
	}
}
