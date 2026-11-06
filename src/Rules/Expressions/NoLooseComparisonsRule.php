<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, UnaryOpNode};
use PhpSyntax\Nodes\Scalar\{FloatNode, IntegerNode};


/**
 * Strict comparison everywhere: `===` for `==`, `!==` for `!=` and `<>`. Risky: a loose comparison that
 * relied on type juggling changes its result. Without the types, operands of one type, which compare the same
 * either way, are not told from others; where the types say the operands are scalars of types with no value in
 * common, the strict comparison would never be true, so the comparison is reported with no fix, unless one of them
 * is a decimal number beside an integer or a float, which is then written as a number of that type (`$ratio === 0.0`).
 */
#[RuleInfo(Stage::Structure, analyses: [Types::class])]
final class NoLooseComparisonsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'expressions.comparison.loose',
			Domain::state('forbidden'),
			'A loose comparison, `==`, `!=` or `<>`, is written `===` or `!==`',
		)];
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
		$types = $context->findAnalysis(Types::class);
		$alike = $types?->isComparedAlike([$node->left, $node->right]) ?? Tristate::Maybe;
		$retyped = $types !== null && $alike === Tristate::No ? self::findRetypedNumber($node, $types) : null;
		if ($alike === Tristate::No && $retyped === null) {
			$context->report($node->operator, "$message, but the types of its operands differ.", fixable: false);
			return;
		} elseif (!$context->report(
			$node->operator,
			"$message.",
			risk: $alike === Tristate::Maybe ? Risk::TypeUnknown : null,
			because: $alike === Tristate::Maybe ? 'the operands may differ in type, which the loose comparison converted' : null,
		)) {
			return;
		}

		if ($retyped !== null) {
			[$number, $code] = $retyped;
			$number->replaceWith((new Builder)->expression($code));
		}

		$node->operator->replaceWith(Token::fromText($text));
	}


	/**
	 * The decimal number of one operand written as the type the other one has, which then compares the same strictly:
	 * `0.0` for `0` beside a float, which PHP converts the integer to, and `1` for `1.0` beside an integer, a float
	 * below 2^53 holding every integer exactly; null where there is none.
	 * @return array{IntegerNode|FloatNode, string}|null  the number and the code written for it
	 */
	private static function findRetypedNumber(BinaryOpNode $node, Types $types): ?array
	{
		foreach ([[$node->left, $node->right], [$node->right, $node->left]] as [$operand, $other]) {
			$number = $operand instanceof UnaryOpNode && $operand->operator->is('-') ? $operand->expression : $operand;
			if ($number instanceof IntegerNode && $number->base === 10 && $types->isOfType($other, 'float') === Tristate::Yes) {
				return [$number, $number->token->text . '.0'];

			} elseif (
				$number instanceof FloatNode
				&& preg_match('~^[\d_]*\.[\d_]*$~D', $number->token->text) === 1 // no exponent
				&& ($value = $number->toValue()) === floor($value)
				&& $value < 2 ** 53
				&& $types->isOfType($other, 'int') === Tristate::Yes
			) {
				return [$number, (string) (int) $value];
			}
		}

		return null;
	}
}
