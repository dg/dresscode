<?php declare(strict_types=1);

namespace DressCode\Rules\Arrays;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayNode;
use PhpSyntax\Nodes\Expression\ListNode;
use PhpSyntax\Token;
use function ord;


/**
 * The short syntax: `[1, 2]` instead of `array(1, 2)` and `[$a, $b] = ...` instead of `list($a, $b) = ...`.
 * A keyword carrying a comment stays, the comment having nowhere to go.
 */
#[RuleInfo(
	'dresscode/short-array-syntax',
	Stage::Structure,
	description: 'Writes arrays and destructuring with the short syntax',
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
