<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ArrayItemNode, DestructuringNode};
use PhpSyntax\Nodes\Expression\ArrayNode;


/**
 * The short syntax: `[1, 2]` instead of `array(1, 2)` and `[$a, $b] = ...` instead of `list($a, $b) = ...`.
 * A comment between the keyword and the parenthesis keeps the long syntax, having nowhere to go, and so does a
 * silenced `list()`; destructurings nested in one another then all keep it, PHP refusing to mix the two.
 */
#[RuleInfo(Stage::Structure)]
final class NoLongArraySyntaxRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('literals.longArraySyntax', Domain::state('forbidden'), '`array(1, 2)` is `[1, 2]`, `list($a) =` is `[$a] =`')];
	}


	public function getVisitedNodes(): array
	{
		return [ArrayNode::class, DestructuringNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof ArrayNode) {
			$keyword = $node->arrayKeyword;
			if ($keyword !== null
				&& !$keyword->hasCommentUpTo($node->openDelimiter)
				&& $context->report($keyword, 'The array must be written `[…]` instead of `array(…)`.')
			) {
				self::shorten($node, $keyword);
			}

		} elseif ($node instanceof DestructuringNode && !$node->parent instanceof ArrayItemNode) {
			$nest = $this->collectNest($node);
			foreach ($nest as [$list, $keyword]) {
				if ($keyword->hasCommentUpTo($list->openDelimiter) || $context->isSilenced($keyword)) {
					return;
				}
			}

			foreach ($nest as [$list, $keyword]) {
				if ($context->report($keyword, 'The destructuring must be written `[…]` instead of `list(…)`.')) {
					self::shorten($list, $keyword);
				}
			}
		}
	}


	/**
	 * The `list()` destructurings of the nest the node opens, each with its keyword.
	 * @return list<array{DestructuringNode, Token}>
	 */
	private function collectNest(DestructuringNode $node): array
	{
		$nest = $node->listKeyword === null ? [] : [[$node, $node->listKeyword]];
		foreach ($node->items as $item) {
			if ($item instanceof ArrayItemNode && $item->value instanceof DestructuringNode) {
				array_push($nest, ...$this->collectNest($item->value));
			}
		}

		return $nest;
	}


	private static function shorten(ArrayNode|DestructuringNode $node, Token $keyword): void
	{
		$open = Token::fromText('[')
			->setLeadingTrivia($keyword->leadingTrivia)
			->setTrailingTrivia($node->openDelimiter->trailingTrivia);
		if ($node instanceof ArrayNode) {
			$node->arrayKeyword = null;
		} else {
			$node->listKeyword = null;
		}

		$node->openDelimiter = $open;
		$node->closeDelimiter->replaceWith(Token::fromText(']'));
	}
}
