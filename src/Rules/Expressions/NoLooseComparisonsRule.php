<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\BinaryOpNode;


/**
 * Strict comparison everywhere: `===` for `==`, `!==` for `!=` and `<>`. Risky: a loose comparison that
 * relied on type juggling changes its result.
 */
#[RuleInfo(Stage::Structure)]
final class NoLooseComparisonsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('expressions.comparison', new Words(['strict' => '`===` and `!==`, never `==` and `!=`']), 'A comparison of equality')];
	}


	public function getVisitedNodes(): array
	{
		return [BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BinaryOpNode) {
			return;
		}

		$text = match ($node->operator->id) {
			Token::IsEqual => '===',
			Token::IsNotEqual => '!==',
			default => null,
		};
		if ($text === null) {
			return;
		}

		if (!$context->report(
			$node->operator,
			"The `{$node->operator->text}` comparison must be written `$text`.",
			risk: Risk::TypeUnknown,
			because: 'the operands may differ in type, which the loose comparison converted',
		)) {
			return;
		}

		$node->operator->replaceWith(Token::fromText($text));
	}
}
