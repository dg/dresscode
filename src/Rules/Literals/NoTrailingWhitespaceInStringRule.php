<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\{InterpolatedStringPartNode, StringNode};
use PhpSyntax\Nodes\Statement\InlineHtmlNode;


/**
 * No whitespace before a line ending inside a multi-line string or inline HTML; this changes
 * the value of the string.
 */
#[RuleInfo(
	'dresscode/no-trailing-whitespace-in-string',
	Stage::Structure,
	description: 'Removes trailing whitespace from string lines',
	group: Group::Correctness,
	risky: true,
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
		if ($token === null) {
			return;
		}

		$stripped = preg_replace('~[ \t]+(?=\R)~', '', $token->text);
		if (
			$stripped === $token->text
			|| !$context->report($token, 'Trailing whitespace in a string')
		) {
			return;
		}

		$token->setText($stripped);
	}
}
