<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Count;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\{FloatNode, IntegerNode};
use function strlen;


/**
 * Long decimal numbers get the underscore separator every three digits from the configured length on
 * (`1_000_000`, `1.234_567`). A number in another base is left alone: the digits of an address, a mask or
 * a code point are grouped by what they mean, not by threes. A number written with a separator already stays
 * as it is. A number both of whose parts change is reported once, under `digitGroupsFrom`.
 */
#[RuleInfo(Stage::Structure)]
final class NumericLiteralSeparatorRule extends NodeRule
{
	private const Integer = 'literals.digitGroupsFrom';
	private const Fraction = 'literals.fractionDigitGroupsFrom';

	/** the digits from which a part is grouped, null where it is kept */
	private ?int $minIntegerDigits = 4;
	private ?int $minFractionDigits = 4;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Integer, new Count(1, range: false), 'The number of digits from which a decimal integer, or the integer part of a decimal number, has its digits grouped by three, `1_000`; a number grouped already stays'),
			new Decision(self::Fraction, new Count(1, range: false), 'The number of digits from which the fraction of a decimal number has its digits grouped by three, `0.000_1`'),
		];
	}


	public function configure(Values $values): void
	{
		$this->minIntegerDigits = $values->find(self::Integer)?->getCount()[0];
		$this->minFractionDigits = $values->find(self::Fraction)?->getCount()[0];
	}


	public function getVisitedNodes(): array
	{
		return [IntegerNode::class, FloatNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof IntegerNode && !$node instanceof FloatNode) {
			return;
		}

		$text = $node->token->text;
		if (str_contains($text, '_')) {
			return;
		}

		if (preg_match('~^0[xXbBoO0-9]~', $text)) {
			return;
		} elseif (preg_match('~^(\d*)(\.\d*)?([eE][+-]?\d+)?$~', $text, $m)) {
			$integer = $this->minIntegerDigits !== null && strlen($m[1]) >= $this->minIntegerDigits ? self::group($m[1], fromEnd: true) : $m[1];
			$fraction = $this->minFractionDigits !== null && isset($m[2]) && strlen($m[2]) - 1 >= $this->minFractionDigits ? '.' . self::group(substr($m[2], 1), fromEnd: false) : ($m[2] ?? '');
			$grouped = $integer . $fraction . ($m[3] ?? '');
		} else {
			return;
		}

		$decision = $integer === $m[1] ? self::Fraction : self::Integer;
		if ($grouped !== $text && $context->report($node, "The number `$text` must be written `$grouped`.", decision: $decision)) {
			$node->token->setText($grouped);
		}
	}


	private static function group(string $digits, bool $fromEnd): string
	{
		if ($fromEnd) {
			return strrev(implode('_', str_split(strrev($digits), 3)));
		}

		return implode('_', str_split($digits, 3));
	}
}
