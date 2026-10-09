<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\Types;
use DressCode\{Decision, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\BinaryOpNode;


/**
 * Strict comparison everywhere: `===` for `==`, `!==` for `!=` and `<>`. Risky: a loose comparison that
 * relied on type juggling changes its result. Without the types, operands of one type, which compare the same
 * either way, are not told from others; where the types say the operands are scalars of types with no value in
 * common, the strict comparison would never be true, so the comparison is reported with no fix.
 */
#[RuleInfo(Stage::Structure, analyses: [Types::class])]
final class NoLooseComparisonsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('expressions.comparison.equality', new Words(['strict' => '`===` and `!==`, never `==` and `!=`']), 'A comparison of equality')];
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

		$message = "The `{$node->operator->text}` comparison must be written `$text`";
		$alike = $context->findAnalysis(Types::class)?->isComparedAlike([$node->left, $node->right]) ?? Tristate::Maybe;
		if ($alike === Tristate::No) {
			$context->report($node->operator, "$message, but the types of its operands differ.", fixable: false);
			return;
		} elseif (!$context->report(
			$node->operator,
			"$message.",
			risk: $alike === Tristate::Yes ? null : Risk::TypeUnknown,
			because: $alike === Tristate::Yes ? null : 'the operands may differ in type, which the loose comparison converted',
		)) {
			return;
		}

		$node->operator->replaceWith(Token::fromText($text));
	}
}
