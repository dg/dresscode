<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{ArrayNode, ListNode};
use function ord;


/**
 * The short syntax: `[1, 2]` instead of `array(1, 2)` and `[$a, $b] = ...` instead of `list($a, $b) = ...`.
 * A keyword carrying a comment stays, the comment having nowhere to go.
 */
#[RuleInfo(
	'dresscode/short-array-syntax',
	Stage::Structure,
	description: 'Writes arrays and destructuring with the short syntax',
	group: Group::Modernization,
)]
final class ShortArraySyntaxRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ArrayNode::class, ListNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ArrayNode && !$node instanceof ListNode) {
			return;
		}

		[$keyword, $message] = $node instanceof ArrayNode
			? [$node->arrayKeyword, 'An array must be written with the short syntax']
			: [$node->listKeyword, 'Destructuring must be written with the short syntax'];
		if ($keyword === null || $keyword->hasComment() || !$context->report($keyword, $message)) {
			return;
		}

		$open = new Token(ord('['), '[');
		$open->setLeadingTrivia($keyword->leadingTrivia);
		$open->setTrailingTrivia($node->openDelimiter->trailingTrivia);
		$close = new Token(ord(']'), ']');
		$close->setLeadingTrivia($node->closeDelimiter->leadingTrivia);
		$close->setTrailingTrivia($node->closeDelimiter->trailingTrivia);
		if ($node instanceof ArrayNode) {
			$node->arrayKeyword = null;
		} else {
			$node->listKeyword = null;
		}

		$node->openDelimiter = $open;
		$node->closeDelimiter = $close;
	}
}
