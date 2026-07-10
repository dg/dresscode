<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, TokenKind};
use PhpSyntax\Nodes\Expression\BinaryOpNode;


/**
 * Strict comparison everywhere: `===` for `==`, `!==` for `!=` and `<>`. Risky: a loose comparison that
 * relied on type juggling changes its result. Without the types, operands of one type, which compare the same
 * either way, are not told from others.
 */
#[RuleInfo(
	'dresscode/strict-comparison',
	Stage::Structure,
	description: 'Replaces loose comparisons with strict ones',
	risky: true,
)]
final class StrictComparisonRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BinaryOpNode) {
			return;
		}

		[$kind, $text] = match ($node->operator->kind) {
			TokenKind::IsEqual => [TokenKind::IsIdentical, '==='],
			TokenKind::IsNotEqual => [TokenKind::IsNotIdentical, '!=='],
			default => [null, null],
		};
		if (
			$kind === null
			|| $text === null
			|| !$context->report($node->operator, "The {$node->operator->text} comparison must be written '$text'")
		) {
			return;
		}

		$operator = new Token($kind, $text);
		$operator->setLeadingTrivia($node->operator->leadingTrivia);
		$operator->setTrailingTrivia($node->operator->trailingTrivia);
		$node->operator = $operator;
	}
}
