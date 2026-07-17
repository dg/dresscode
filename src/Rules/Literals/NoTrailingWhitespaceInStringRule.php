<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{NodeRule, Risk, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\{InterpolatedStringPartNode, StringNode};
use PhpSyntax\Nodes\Statement\InlineHtmlNode;


/**
 * No whitespace before a line ending inside a multi-line string or inline HTML; this changes
 * the value of the string.
 */
#[RuleInfo(
	'dresscode/noTrailingWhitespaceInString',
	Stage::Structure,
	description: 'Removes trailing whitespace from string lines',
	group: RuleGroup::Correctness,
)]
final class NoTrailingWhitespaceInStringRule extends NodeRule
{
	public function getVisitedTypes(): array
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
			|| !$context->report($token, 'Trailing whitespace in a string', risk: Risk::BehaviorChanges)
		) {
			return;
		}

		$token->setText($stripped);
	}
}
