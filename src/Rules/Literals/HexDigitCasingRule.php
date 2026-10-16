<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\IntegerNode;


/**
 * The digits `A` to `F` of a hexadecimal number in one case, `0xFF` or `0xff`, and the prefix of a number in another
 * base in lowercase, `0x`, `0b` and `0o`.
 */
#[RuleInfo(Stage::Structure)]
final class HexDigitCasingRule extends NodeRule
{
	private bool $uppercase = true;


	public static function getDecisions(): array
	{
		return [new Decision('casing.hexDigit', new Words([
			'uppercase' => '`0xFF`',
			'lowercase' => '`0xff`',
		]), 'The digits `A` to `F` of a hexadecimal number; the prefixes `0x`, `0b` and `0o` stand in lowercase either way')];
	}


	public function configure(Values $values): void
	{
		$this->uppercase = $values->get('casing.hexDigit')->getWord() === 'uppercase';
	}


	public function getVisitedNodes(): array
	{
		return [IntegerNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof IntegerNode || !preg_match('~^0[xXbBoO]~', $text = $node->token->text)) {
			return;
		}

		$digits = substr($text, 2);
		$written = strtolower(substr($text, 0, 2)) . match (true) {
			$text[1] !== 'x' && $text[1] !== 'X' => $digits,
			$this->uppercase => strtoupper($digits),
			default => strtolower($digits),
		};
		if ($written !== $text && $context->report($node, "The number `$text` must be written `$written`.")) {
			$node->token->setText($written);
		}
	}
}
