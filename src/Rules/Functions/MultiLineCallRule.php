<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Claim, Gap, GapRule, Line, RuleInfo, Space, Stage};
use PhpSyntax\Node;
use PhpSyntax\Nodes\{ArgumentListNode, VariadicPlaceholderNode};


/**
 * The arguments of a call spread over several lines each take a line of their own, with the closing
 * parenthesis on a line of its own and each comma on the line of its argument; a call kept on one line
 * is left alone, and where the lines stand is the matter of dresscode/indentation. The parentheses of a
 * first-class callable, `strlen(...)`, stay on one line.
 */
#[RuleInfo(
	'dresscode/multi-line-call',
	Stage::Formatting,
	description: 'Puts every argument of a multi-line call on its own line',
)]
final class MultiLineCallRule extends GapRule
{
	public function getClaims(): array
	{
		$because = 'the arguments span several lines';
		$break = new Claim(line: Line::Next, because: $because);
		$hug = new Claim(Space::None, line: Line::Same, because: $because);
		$callable = new Claim(line: Line::Same, because: 'a first-class callable has no arguments to spread');
		$decide = fn(Gap $gap, ?Node $list) => match (true) {
			self::isCallable($list) => $callable,
			self::isBroken($gap, $list) => $break,
			default => null,
		};
		return [ArgumentListNode::class => [
			'items:item' => [fn(Gap $gap) => $decide($gap, $gap->value->parent?->parent), null],
			'items:separator' => [fn(Gap $gap) => self::isBroken($gap, $gap->token->parent?->parent) ? $hug : null, null],
			'closeParen' => [fn(Gap $gap) => $decide($gap, $gap->token->parent), null],
		]];
	}


	/** Whether the parentheses are those of a first-class callable, `strlen(...)`. */
	private static function isCallable(?Node $list): bool
	{
		return $list instanceof ArgumentListNode && ($list->items->getItems()[0] ?? null) instanceof VariadicPlaceholderNode;
	}


	/**
	 * Whether the call spreads over lines: a line break after the opening parenthesis, an argument starting
	 * a line, or the closing parenthesis doing so. A comment after the parenthesis ends its line without
	 * breaking the list.
	 */
	private static function isBroken(Gap $gap, ?Node $list): bool
	{
		if (!$list instanceof ArgumentListNode || $list->items->isEmpty()) {
			return false;
		}

		return $gap->once($list, fn() => self::isBrokenNow($list));
	}


	private static function isBrokenNow(ArgumentListNode $list): bool
	{
		$open = $list->openParen;
		if (($open->getTrailingSpace() === null && !$open->hasComment()) || $list->closeParen->startsLine()) {
			return true;
		}

		return array_any($list->items->getItems(), fn(Node $arg) => $arg->getFirstToken()?->startsLine() === true);
	}
}
