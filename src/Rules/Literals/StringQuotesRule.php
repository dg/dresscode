<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\StringNode;


/**
 * Which quotes a plain string is written with, that is one that gains nothing from either: no interpolation,
 * no escape sequence that only double quotes know, and no quote of the wanted kind inside. A string that
 * needs what it has is left alone whichever way the decision goes.
 */
#[RuleInfo(Stage::Structure)]
final class StringQuotesRule extends NodeRule
{
	private const Single = 'single';
	private const Double = 'double';

	private string $quotes;


	public static function getDecisions(): array
	{
		return [
			new Decision('literals.quotes', new Words([
				self::Single => '`\'text\'` where double quotes gain nothing',
				self::Double => '`"text"` where single quotes gain nothing',
			]), 'The quotes of a plain string, one without interpolation, without an escape sequence only double quotes know and without a quote of the wanted kind inside'),
		];
	}


	public function configure(Values $values): void
	{
		$this->quotes = $values->get('literals.quotes')->getWord();
	}


	public function getVisitedNodes(): array
	{
		return [StringNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof StringNode) {
			return;
		}

		[$from, $to, $message] = $this->quotes === self::Single
			? ['"', "'", 'A plain string must be written with single quotes.']
			: ["'", '"', 'A plain string must be written with double quotes.'];
		$content = substr($node->token->text, 1, -1);
		if (
			$node->quote !== $from
			|| str_contains($content, $to)
			|| !self::isPlain($content, $from)
			|| !$context->report($node, $message)
		) {
			return;
		}

		$node->setValue($node->toValue(), $to);
	}


	/**
	 * Whether the content says the same in either kind of quotes: what a double-quoted string escapes is
	 * only the backslash and its own quote, and a single-quoted one has nothing that interpolation would read.
	 */
	private static function isPlain(string $content, string $quote): bool
	{
		return $quote === '"'
			? !str_contains(str_replace(['\\\\', '\"'], '', $content), '\\')
			: !str_contains($content, '$'); // a brace opens an interpolation only before `$`
	}
}
