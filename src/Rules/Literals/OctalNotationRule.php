<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\IntegerNode;


/**
 * Octal numbers with the explicit `0o` prefix of PHP 8.1: `0o755`, not `0755`.
 */
#[RuleInfo(
	'dresscode/octal-notation',
	Stage::Structure,
	description: 'Writes octal numbers with the 0o prefix',
	group: Group::Modernization,
	requires: ['php' => '>=8.1'],
)]
final class OctalNotationRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [IntegerNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof IntegerNode) {
			return;
		}

		$text = (string) preg_replace('~^0_*([0-7_]+)$~', '0o$1', $node->token->text);
		if (
			$text !== $node->token->text
			&& $context->report($node, "An octal number must be written with the '0o' prefix")
		) {
			$node->token->setText($text);
		}
	}
}
