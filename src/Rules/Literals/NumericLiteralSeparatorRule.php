<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, DecisionKind, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Count;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\{FloatNode, IntegerNode};
use function strlen;


/**
 * Long decimal numbers get the underscore separator every three digits from the configured length on
 * (`1_000_000`, `1.234_567`). A number in another base is left alone: the digits of an address, a mask or
 * a code point are grouped by what they mean, not by threes. A number written with a separator already stays
 * as it is. With `forbidden` the separators go, for the integer from a number in any base and from an exponent too.
 * A number both of whose parts change is reported once, under `digitSeparator.integer`.
 */
#[RuleInfo(Stage::Structure)]
final class NumericLiteralSeparatorRule extends NodeRule
{
	private const Integer = 'literals.digitSeparator.integer';
	private const Fraction = 'literals.digitSeparator.fraction';

	/** what each part is written with, `required` or `forbidden`, null where it is kept */
	private ?string $integer = null;
	private ?string $fraction = null;

	/** the digits from which a part is grouped */
	private int $minIntegerDigits = 4;
	private int $minFractionDigits = 4;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::Integer,
				Domain::state('required', 'forbidden'),
				'The separator grouping by three the digits of a decimal integer, or of the integer part of a decimal number, `1_000`; a number grouped already stays',
			),
			new Decision(
				self::Fraction,
				Domain::state('required', 'forbidden'),
				'The separator grouping by three the digits of the fraction of a decimal number, `0.000_1`',
			),
			new Decision(
				'literals.digitSeparatorMinDigits.integer',
				new Count(1, range: false),
				'The number of digits from which `literals.digitSeparator.integer` groups them',
				kind: DecisionKind::Parameter,
				default: 4,
			),
			new Decision(
				'literals.digitSeparatorMinDigits.fraction',
				new Count(1, range: false),
				'The number of digits from which `literals.digitSeparator.fraction` groups them',
				kind: DecisionKind::Parameter,
				default: 4,
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->integer = $values->find(self::Integer)?->getWord();
		$this->fraction = $values->find(self::Fraction)?->getWord();
		$this->minIntegerDigits = $values->get('literals.digitSeparatorMinDigits.integer')->getCount()[0];
		$this->minFractionDigits = $values->get('literals.digitSeparatorMinDigits.fraction')->getCount()[0];
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
		if (preg_match('~^0[xXbBoO0-9]~', $text)) {
			$written = $this->integer === 'forbidden' ? str_replace('_', '', $text) : $text;
			$decision = self::Integer;
		} elseif (preg_match('~^([\d_]*)(\.[\d_]*)?([eE][+-]?[\d_]+)?$~', $text, $m)) {
			$grouped = str_contains($text, '_');
			$integer = self::writePart($m[1], $this->integer, $grouped ? null : $this->minIntegerDigits, fromEnd: true);
			$fraction = ($m[2] ?? '') === ''
				? ''
				: '.' . self::writePart(substr($m[2], 1), $this->fraction, $grouped ? null : $this->minFractionDigits, fromEnd: false);
			$exponent = $m[3] ?? '';
			$written = $integer . $fraction . ($this->integer === 'forbidden' ? str_replace('_', '', $exponent) : $exponent);
			$decision = $integer === $m[1] ? self::Fraction : self::Integer;
		} else {
			return;
		}

		if ($written !== $text && $context->report($node, "The number `$text` must be written `$written`.", decision: $decision)) {
			$node->token->setText($written);
		}
	}


	/**
	 * The digits of a part as the decision of the part writes them.
	 * @param  ?int  $minDigits  the digits from which they are grouped, null for a number grouped already
	 */
	private static function writePart(string $digits, ?string $word, ?int $minDigits, bool $fromEnd): string
	{
		return match (true) {
			$word === 'forbidden' => str_replace('_', '', $digits),
			$word === 'required' && $minDigits !== null && strlen($digits) >= $minDigits => self::group($digits, $fromEnd),
			default => $digits,
		};
	}


	private static function group(string $digits, bool $fromEnd): string
	{
		if ($fromEnd) {
			return strrev(implode('_', str_split(strrev($digits), 3)));
		}

		return implode('_', str_split($digits, 3));
	}
}
