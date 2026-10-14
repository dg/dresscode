<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, DecisionKind, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\{Count, Words};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\{FloatNode, IntegerNode};
use function strlen;


/**
 * The digits `A` to `F` of a hexadecimal number in one case, `0xFF` or `0xff`, and the prefix of a number in another
 * base in lowercase, `0x`, `0b` and `0o`. Long decimal numbers get the underscore separator every three digits from
 * the configured length on (`1_000_000`, `1.234_567`), from PHP 7.4 on. A number in another base is left alone: the digits of an
 * address, a mask or a code point are grouped by what they mean, not by threes. A number written with a separator
 * already stays as it is. With `forbidden` the separators go, for the integer from a number in any base and from an
 * exponent too. A number both of whose parts change is reported once, under `digitSeparator.integer`.
 */
#[RuleInfo(Stage::Structure)]
final class NumberNotationRule extends NodeRule
{
	private const HexDigit = 'casing.hexDigit';
	private const Integer = 'literals.digitSeparator.integer';
	private const Fraction = 'literals.digitSeparator.fraction';

	/** whether the hexadecimal digits are written in uppercase, null where they are kept */
	private ?bool $uppercase = null;

	/** what each part is written with, `required` or `forbidden`, null where it is kept */
	private ?string $integer = null;
	private ?string $fraction = null;

	/** the digits from which a part is grouped */
	private int $minIntegerDigits = 4;
	private int $minFractionDigits = 4;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::HexDigit, new Words([
				'uppercase' => '`0xFF`',
				'lowercase' => '`0xff`',
			]), 'The digits `A` to `F` of a hexadecimal number; the prefixes `0x`, `0b` and `0o` stand in lowercase either way'),
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
		$hexDigit = $values->find(self::HexDigit)?->getWord();
		$this->uppercase = $hexDigit === null ? null : $hexDigit === 'uppercase';
		$this->integer = $values->find(self::Integer)?->getWord();
		$this->fraction = $values->find(self::Fraction)?->getWord();
		$this->minIntegerDigits = $values->get('literals.digitSeparatorMinDigits.integer')->getCountRange()[0];
		$this->minFractionDigits = $values->get('literals.digitSeparatorMinDigits.fraction')->getCountRange()[0];
	}


	public function getVisitedNodes(): array
	{
		$separators = $this->integer !== null || $this->fraction !== null;
		return [
			...($this->uppercase !== null || $separators ? [IntegerNode::class] : []),
			...($separators ? [FloatNode::class] : []),
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($this->uppercase !== null && $node instanceof IntegerNode) {
			$this->fixHexDigits($node, $context);
		}

		if (($this->integer !== null || $this->fraction !== null) && ($node instanceof IntegerNode || $node instanceof FloatNode)) {
			$this->fixSeparators($node, $context);
		}
	}


	private function fixHexDigits(IntegerNode $node, RuleContext $context): void
	{
		if (!preg_match('~^0[xXbBoO]~', $text = $node->token->text)) {
			return;
		}

		$digits = substr($text, 2);
		$written = strtolower(substr($text, 0, 2)) . match (true) {
			$text[1] !== 'x' && $text[1] !== 'X' => $digits,
			$this->uppercase => strtoupper($digits),
			default => strtolower($digits),
		};
		if ($written !== $text && $context->report($node, "The number `$text` must be written `$written`.", decision: self::HexDigit)) {
			$node->token->setText($written);
		}
	}


	private function fixSeparators(IntegerNode|FloatNode $node, RuleContext $context): void
	{
		$text = $node->token->text;
		if (preg_match('~^0[xXbBoO0-9]~', $text)) {
			$written = $this->integer === 'forbidden' ? str_replace('_', '', $text) : $text;
			$decision = self::Integer;
		} elseif (preg_match('~^([\d_]*)(\.[\d_]*)?([eE][+-]?[\d_]+)?$~', $text, $m)) {
			// a number grouped already stays, and PHP before 7.4 has no separator
			$groups = !str_contains($text, '_') && version_compare($context->phpVersion, '7.4', '>=');
			$integer = self::renderPart($m[1], $this->integer, $groups ? $this->minIntegerDigits : null, fromEnd: true);
			$fraction = ($m[2] ?? '') === ''
				? ''
				: '.' . self::renderPart(substr($m[2], 1), $this->fraction, $groups ? $this->minFractionDigits : null, fromEnd: false);
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
	private static function renderPart(string $digits, ?string $word, ?int $minDigits, bool $fromEnd): string
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
