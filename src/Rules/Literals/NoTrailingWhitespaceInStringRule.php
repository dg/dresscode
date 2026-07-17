<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\{InterpolatedStringPartNode, StringNode};
use PhpSyntax\Nodes\Statement\InlineHtmlNode;


/**
 * No whitespace before a line ending inside a multi-line string or inline HTML; this changes
 * the value of the string.
 */
#[RuleInfo(Stage::Structure)]
final class NoTrailingWhitespaceInStringRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.trailingWhitespaceInString', Domain::state('forbidden'), 'At the end of the lines of a multi-line string or inline HTML, which changes their value')];
	}


	public function getVisitedNodes(): array
	{
		return [StringNode::class, InterpolatedStringPartNode::class, InlineHtmlNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$token = match (true) {
			$node instanceof StringNode => $node->token,
			$node instanceof InterpolatedStringPartNode => $node->token,
			$node instanceof InlineHtmlNode => $node->html,
			default => null,
		};
		// what \R matches outside the UTF mode, so that a string with no line ending is left without the expression
		if ($token === null || strpbrk($token->text, "\r\n\x0B\x0C\x85") === false) {
			return;
		}

		$stripped = preg_replace('~[ \t]+(?=\R)~', '', $token->text);
		if (
			$stripped === $token->text
			|| !$context->report($token, 'Expected no whitespace at the end of a line in the string.', risk: Risk::BehaviorChanges, because: 'the whitespace is a part of the value')
		) {
			return;
		}

		$token->setText($stripped);
	}
}
