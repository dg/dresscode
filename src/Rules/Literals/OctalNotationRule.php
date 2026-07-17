<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\IntegerNode;


/**
 * Octal numbers with the explicit `0o` prefix of PHP 8.1: `0o755`, not `0755`.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.1'])]
final class OctalNotationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.syntax.octalPrefix', Domain::adopted(), '`0o17` for `017`')];
	}


	public function getVisitedNodes(): array
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
			&& $context->report($node, "The octal number `{$node->token->text}` must be written `$text`.")
		) {
			$node->token->setText($text);
		}
	}
}
