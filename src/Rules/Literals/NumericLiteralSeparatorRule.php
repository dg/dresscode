<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\{FloatNode, IntegerNode};
use function strlen;


/**
 * Long decimal numbers get the underscore separator every three digits from the configured length on
 * (`1_000_000`, `1.234_567`). A number in another base is left alone: the digits of an address, a mask or
 * a code point are grouped by what they mean, not by threes. A number written with a separator already stays
 * as it is.
 */
#[RuleInfo(
	'dresscode/numericLiteralSeparator',
	Stage::Structure,
	description: 'Groups the digits of long numbers with underscores',
)]
final class NumericLiteralSeparatorRule extends NodeRule implements ConfigurableRule
{
	private int $minIntegerDigits = 4;
	private int $minFractionDigits = 4;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'minIntegerDigits' => Expect::int(4)->min(1)->description('An integer part with at least this many digits is grouped'),
			'minFractionDigits' => Expect::int(4)->min(1)->description('A fraction with at least this many digits is grouped'),
		]);
	}


	public function configure(array $options): void
	{
		$this->minIntegerDigits = $options['minIntegerDigits'];
		$this->minFractionDigits = $options['minFractionDigits'];
	}


	public function getVisitedTypes(): array
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
			$integer = strlen($m[1]) >= $this->minIntegerDigits ? self::group($m[1], fromEnd: true) : $m[1];
			$fraction = isset($m[2]) && strlen($m[2]) - 1 >= $this->minFractionDigits ? '.' . self::group(substr($m[2], 1), fromEnd: false) : ($m[2] ?? '');
			$grouped = $integer . $fraction . ($m[3] ?? '');
		} else {
			return;
		}

		if ($grouped !== $text && $context->report($node, 'A long number must group its digits with underscores')) {
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
